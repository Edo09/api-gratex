<?php
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../MasterDatabase.php');
require_once(__DIR__ . '/../Utils/Cobros/CobroReglas.php');

/**
 * Cobros (cuentas por cobrar por cliente): las tablas cobro_config, cobros, cobro_pagos y
 * cobro_recordatorios de la migracion 038. Las decisiones (estado, vencimiento, validacion)
 * viven en CobroReglas; aqui solo hay SQL y transacciones. Reglas y valores por defecto:
 * docs/modules/cobros.md.
 *
 * Invariantes que este modelo sostiene (y que tools/test_cobros.php prueba):
 *   - Un cobro por cliente y periodo: la UNIQUE uk_cobros_cliente_periodo es la red de
 *     seguridad; crear o generar sobre un periodo ya cobrado no duplica nada.
 *   - cobros.monto_pagado se RECALCULA desde los pagos vigentes en la misma transaccion que
 *     cada pago o anulacion, con la fila del cobro bloqueada (SELECT ... FOR UPDATE): dos
 *     pagos a la vez se esperan uno al otro y ninguno pisa el saldo del otro.
 *   - Un pago nunca pasa del saldo, ni se registra en un cobro cancelado.
 *   - Cuando un cobro queda pagado completo se genera el del mes siguiente (monto mensual
 *     ACTUAL del cliente), en la MISMA transaccion y solo si la configuracion esta activa.
 *     Repetir la peticion no genera otro: el periodo ya existe.
 *   - Nada se borra: un cobro se cancela, un pago se anula, y todo queda a la vista.
 *   - El estado no se guarda: se deriva en cada lectura (CobroReglas::estado).
 *
 * DOS ambitos con el mismo modelo y las mismas reglas (el ambito decide quien es "el cliente" de un cobro):
 *   - AMBITO_EMPRESA (por defecto): una empresa cobra a SUS clientes; las tablas estan en la base de la
 *     empresa (migracion de tenant 038) y el cliente es una fila de su tabla clients.
 *   - AMBITO_TENANTS: la PLATAFORMA cobra a sus tenants (las empresas que usan Fiscalpoint); las mismas
 *     cuatro tablas estan en la base MASTER (migracion master 021, con FK a tenants) y el cliente es una
 *     fila de tenants. Solo lo usa la cuenta de soporte (acceso global): cobroController lo exige.
 *
 * El PDO se puede inyectar (y el reloj): las pruebas lo corren con SQLite en memoria. El
 * unico SQL que no es igual en MySQL y SQLite es el bloqueo de fila (FOR UPDATE), que se
 * agrega solo en MySQL.
 *
 * Los errores de negocio se devuelven como ['error', texto, http, codigo?, data?]; las
 * lecturas no atrapan la base (un fallo no es "no hay cobros"): cobroController responde
 * el error generico. Las escrituras atrapan el PDOException, lo mandan al error_log y
 * devuelven un 500 con texto neutro.
 */
class cobroModel
{
    public const MSG_GUARDAR = 'No se pudo guardar el cobro. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';
    public const MSG_PAGAR = 'No se pudo registrar el pago. Revisa el cobro antes de intentarlo otra vez: puede que sí se haya guardado.';
    public const MSG_CLAVE_USADA = 'Esta operación ya se registró con otro cobro. Cierra la ventana y ábrela de nuevo.';
    public const MSG_CONFIG = 'No se pudo guardar la configuración del cliente. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';
    public const MSG_CLAVE_OTROS_DATOS = 'Esta operación ya se registró con otros datos (otro monto, fecha o método). Revisa el cobro antes de volver a intentarlo.';
    public const MSG_CLAVE_ANULADA = 'Este pago ya se registró y después se anuló. Cierra la ventana y registra uno nuevo si hace falta.';
    public const MSG_LIMITE_PAGOS = 'Este cobro ya tiene el máximo de pagos registrados (500, contando los anulados). Avisa a soporte.';
    public const MSG_LIMITE_RECORDATORIOS = 'Este cobro ya tiene el máximo de recordatorios anotados (200). Avisa a soporte.';

    /** El cliente de un cobro es una fila de clients de la empresa... */
    public const AMBITO_EMPRESA = 'empresa';
    /** ...o un tenant de la plataforma (base master). */
    public const AMBITO_TENANTS = 'tenants';
    /** Tope de tenants que devuelve el directorio de la plataforma (buscarClientes) en una sola respuesta. */
    public const MAX_DIRECTORIO = 500;

    /**
     * De donde sale "el cliente" de cada ambito, con los mismos nombres de columna que usa CLIENTE (client_name,
     * company_name, razon_social, rnc, email, phone_number). Un tenant solo tiene nombre y RNC: el nombre hace de
     * razon social, empresa y contacto, y el correo es el del primer usuario admin de esa empresa (para saber a quien
     * escribirle). tipo, ambiente, activo y edicion sirven al buscador de tenants.
     *
     * Un tenant con grupo_id (migracion master 008: empresas de un mismo cliente) se cobra POR GRUPO: la UNIDAD de cobro es la
     * cabeza, el tenant cuyo id es el grupo_id (--grupo=self); si esa fila ya no existe, la de menor id con ese grupo_id; sin
     * grupo_id, el tenant solo. cabeza_id es el id de la unidad de cada tenant.
     */
    private const ORIGEN_EMPRESA = 'clients';
    private const ORIGEN_TENANTS = "(SELECT t.id AS id, t.nombre AS client_name, t.nombre AS company_name, t.nombre AS razon_social,
        t.rnc AS rnc, COALESCE((SELECT u.email FROM users u WHERE u.tenant_id = t.id AND u.role = 'admin' ORDER BY u.id LIMIT 1), '') AS email,
        '' AS phone_number, t.tipo AS tipo, t.ambiente AS ambiente, t.activo AS activo, t.edicion AS edicion, t.grupo_id AS grupo_id,
        CASE WHEN t.grupo_id IS NULL THEN t.id
             WHEN EXISTS (SELECT 1 FROM tenants h WHERE h.id = t.grupo_id) THEN t.grupo_id
             ELSE (SELECT MIN(g.id) FROM tenants g WHERE g.grupo_id = t.grupo_id) END AS cabeza_id
        FROM tenants t)";

    /** Los datos del cliente que viajan con un cobro (alias cl_* para no chocar con las columnas de cobros). */
    private const CLIENTE = 'cl.id AS cl_id, cl.client_name AS cl_contacto, cl.company_name AS cl_empresa,
        cl.razon_social AS cl_razon, cl.rnc AS cl_rnc, cl.email AS cl_email, cl.phone_number AS cl_tel';

    /** Un cobro con saldo por cobrar (no cancelado y con saldo mayor que 0), en SQL. */
    private const ABIERTO = '(c.cancelado_at IS NULL AND ROUND(c.monto - c.monto_pagado, 2) > 0)';
    private const SALDO = 'ROUND(c.monto - c.monto_pagado, 2)';

    private PDO $db;
    private bool $mysql;
    private string $ambito;
    /** La tabla (o subconsulta) que hace de clientes en este ambito: ORIGEN_EMPRESA u ORIGEN_TENANTS. */
    private string $origen;
    /** @var callable(): string */
    private $reloj;

    /**
     * @param PDO|null $conexion la de la peticion si no se pasa: la base de la empresa (Database) en el ambito
     *        de empresa, la master (MasterDatabase) en el de tenants
     * @param callable|null $reloj devuelve 'AAAA-MM-DD HH:MM:SS' en hora de RD; el de PHP si no se pasa
     * @param string $ambito AMBITO_EMPRESA (por defecto) o AMBITO_TENANTS
     */
    public function __construct(?PDO $conexion = null, ?callable $reloj = null, string $ambito = self::AMBITO_EMPRESA)
    {
        if ($ambito !== self::AMBITO_EMPRESA && $ambito !== self::AMBITO_TENANTS) {
            throw new InvalidArgumentException('Ambito de cobros desconocido: ' . $ambito);
        }
        $this->ambito = $ambito;
        $this->origen = $ambito === self::AMBITO_TENANTS ? self::ORIGEN_TENANTS : self::ORIGEN_EMPRESA;
        $this->db = $conexion ?? ($ambito === self::AMBITO_TENANTS
            ? MasterDatabase::getInstance()->getConnection()
            : Database::getInstance()->getConnection());
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->reloj = $reloj ?? static fn(): string => date('Y-m-d H:i:s');
    }

    /** Ahora, en hora de RD ('AAAA-MM-DD HH:MM:SS'). */
    public function ahora(): string
    {
        return ($this->reloj)();
    }

    /** Hoy, en hora de RD ('AAAA-MM-DD'): la fecha contra la que se deriva el estado. */
    public function hoy(): string
    {
        return substr($this->ahora(), 0, 10);
    }

    // ========================================================================
    // Clientes y configuracion mensual
    // ========================================================================

    /** El cliente (los datos que se muestran junto a un cobro), o null si no existe. */
    public function cliente(int $id): ?array
    {
        $s = $this->db->prepare('SELECT ' . self::CLIENTE . ' FROM ' . $this->origen . ' cl WHERE cl.id = :id');
        $s->execute([':id' => $id]);
        $f = $s->fetch();
        return $f ? self::clienteJson($f) : null;
    }

    /**
     * Si el tenant es una hermana de un grupo (no la cabeza), la cabeza que cobra por el grupo: ['id' => int, 'nombre' => string];
     * null si es la cabeza, no tiene grupo, no existe, o en el ambito de una empresa (alli no hay grupos).
     */
    private function cabezaDeGrupo(int $clientId): ?array
    {
        if ($this->ambito !== self::AMBITO_TENANTS) {
            return null;
        }
        $s = $this->db->prepare(
            'SELECT h.id AS id, h.client_name AS nombre FROM ' . $this->origen . ' cl JOIN ' . $this->origen
            . ' h ON h.id = cl.cabeza_id WHERE cl.id = :id AND cl.cabeza_id <> cl.id'
        );
        $s->execute([':id' => $clientId]);
        $f = $s->fetch();
        return $f ? ['id' => (int) $f['id'], 'nombre' => (string) $f['nombre']] : null;
    }

    /** El rechazo de cobrarle por separado a una hermana de un grupo: el cobro del grupo es el de su cabeza. */
    private static function errMiembroDeGrupo(array $cabeza): array
    {
        return self::err(
            'Esta empresa es parte del grupo de ' . $cabeza['nombre'] . ': el cobro del grupo se maneja en ' . $cabeza['nombre'] . '.',
            409, 'MIEMBRO_DE_GRUPO', ['cabeza_id' => $cabeza['id'], 'cabeza_nombre' => $cabeza['nombre']]
        );
    }

    /**
     * El DIRECTORIO de clientes de la plataforma: los tenants, con lo que hace falta para elegirlos y para ver como van
     * con su cobro. Solo existe en el ambito de tenants (en el de una empresa el buscador es el de Clientes de siempre):
     * ahi devuelve [] . Busca por nombre o RNC; los activos primero y, dentro, por nombre. Lo usan el buscador de los
     * formularios (pocos resultados) y la pestana Clientes de Cobros de tenants (hasta MAX_DIRECTORIO).
     *
     * Cada uno trae lo de un cliente y ademas: que es (tipo, ambiente, activo); su cobro mensual (config, null si no tiene;
     * con_config lo resume); y lo que debe: saldo_pendiente (todo lo abierto), saldo_vencido (lo que ya paso su
     * vencimiento), cuantos cobros abiertos y vencidos tiene, los dias de atraso del mas viejo (dias_atraso, 0 si nada
     * esta vencido) y su proximo vencimiento (el primero que aun no vence; hoy cuenta como "aun no"). Un cobro cancelado
     * o pagado no suma, igual que en el dashboard.
     *
     * Hay UNA fila por unidad de cobro (ver ORIGEN_TENANTS): un grupo es la fila de su cabeza (con su nombre, RNC, tipo, ambiente,
     * cobro mensual y saldos), y sus hermanas no salen solas. `grupo` es null sin grupo; con grupo trae `cabeza_id` y `miembros`
     * (las demas empresas del grupo, por id: id, nombre, rnc, tipo, ambiente; vacio en un grupo de una). La busqueda por nombre o
     * RNC encuentra la unidad por ella o por cualquiera de sus hermanas.
     *
     * Solo salen las unidades que se COBRAN ($conExcluidos = false). Con $conExcluidos salen todas, cada una con `facturable` y,
     * si no se cobra, `motivo_oculto`: `excluido` (la plataforma la marco "no es un cliente", cobro_excluidos; la marca gana) o
     * `solo_certificacion`. Se cobra la unidad que no esta marcada y (tiene su cobro mensual ACTIVO, o alguna de sus empresas es
     * de base propia, o es de integracion en produccion `ecf`). Una de integracion que sigue en certificacion no se cobra hasta
     * que pasa a produccion o alguien le activa su cobro mensual. `excluido` y `motivo_exclusion` dicen solo de la marca.
     *
     * @return list<array<string,mixed>>
     */
    public function buscarClientes(string $texto, int $limite = 20, bool $conExcluidos = false): array
    {
        if ($this->ambito !== self::AMBITO_TENANTS) {
            return [];
        }
        $limite = max(1, min($limite, self::MAX_DIRECTORIO));
        $donde = '1 = 1';
        $params = [];
        $texto = trim($texto);
        if ($texto !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto) . '%';
            // La unidad coincide por si misma o por cualquiera de sus hermanas (escribir el nombre de una encuentra a su grupo).
            $donde = "(cl.client_name LIKE :q1 ESCAPE '!' OR cl.rnc LIKE :q2 ESCAPE '!' OR EXISTS (SELECT 1 FROM " . $this->origen
                . " h WHERE h.cabeza_id = cl.id AND (h.client_name LIKE :q3 ESCAPE '!' OR h.rnc LIKE :q4 ESCAPE '!')))";
            $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        // Se cobra: sin marca de "no es un cliente" y (cobro mensual activo, o alguna empresa de base propia o de integracion en
        // produccion). Solo cuenta el cobro mensual de la cabeza: el que sobre en una hermana no hace a nadie cliente.
        $facturable = "(ex.client_id IS NULL AND (COALESCE(cc.activo, 0) = 1 OR EXISTS (SELECT 1 FROM " . $this->origen
            . " m WHERE m.cabeza_id = cl.id AND (m.tipo = 'app' OR (m.tipo = 'integracion' AND m.ambiente = 'ecf')))))";
        $donde = '(' . $donde . ') AND cl.id = cl.cabeza_id';
        if (!$conExcluidos) {
            $donde .= ' AND ' . $facturable;
        }
        $hoy = $this->hoy();
        $saldo = self::SALDO;
        $s = $this->db->prepare(
            'SELECT ' . self::CLIENTE . ', cl.tipo AS cl_tipo, cl.ambiente AS cl_ambiente, cl.activo AS cl_activo,
                    cl.grupo_id AS cl_grupo_id, ' . $facturable . ' AS cl_facturable,
                    cc.client_id AS cfg_client_id, cc.monto_mensual AS cfg_monto, cc.dia_vencimiento AS cfg_dia,
                    cc.descripcion AS cfg_desc, cc.activo AS cfg_activo, cc.updated_at AS cfg_updated, cc.created_at AS cfg_created,
                    ex.client_id AS ex_client_id, ex.motivo AS ex_motivo,
                    COALESCE(ab.pendiente, 0) AS saldo_pendiente, COALESCE(ab.vencido, 0) AS saldo_vencido,
                    COALESCE(ab.abiertos, 0) AS cobros_abiertos, COALESCE(ab.vencidos, 0) AS cobros_vencidos,
                    ab.mas_antiguo AS mas_antiguo, ab.proximo AS proximo
               FROM ' . $this->origen . ' cl
               LEFT JOIN cobro_config cc ON cc.client_id = cl.id
               LEFT JOIN cobro_excluidos ex ON ex.client_id = cl.id
               LEFT JOIN (
                     SELECT c.client_id AS client_id,
                            SUM(' . $saldo . ') AS pendiente,
                            SUM(CASE WHEN c.fecha_vencimiento < :hoy1 THEN ' . $saldo . ' ELSE 0 END) AS vencido,
                            COUNT(*) AS abiertos,
                            SUM(CASE WHEN c.fecha_vencimiento < :hoy2 THEN 1 ELSE 0 END) AS vencidos,
                            MIN(CASE WHEN c.fecha_vencimiento < :hoy3 THEN c.fecha_vencimiento END) AS mas_antiguo,
                            MIN(CASE WHEN c.fecha_vencimiento >= :hoy4 THEN c.fecha_vencimiento END) AS proximo
                       FROM cobros c WHERE ' . self::ABIERTO . ' GROUP BY c.client_id
                    ) ab ON ab.client_id = cl.id
              WHERE ' . $donde . '
              ORDER BY cl.activo DESC, cl.client_name ASC, cl.id ASC LIMIT ' . $limite
        );
        $s->execute([':hoy1' => $hoy, ':hoy2' => $hoy, ':hoy3' => $hoy, ':hoy4' => $hoy] + $params);
        $filas = $s->fetchAll();
        // Las hermanas de cada unidad (todas las demas empresas de su grupo), de una sola consulta.
        $miembros = [];
        if ($filas !== []) {
            $enLista = implode(', ', array_map(static fn(array $r): int => (int) $r['cl_id'], $filas));
            $mq = $this->db->query(
                'SELECT m.cabeza_id AS cabeza_id, m.id AS id, m.client_name AS nombre, m.rnc AS rnc, m.tipo AS tipo, m.ambiente AS ambiente
                   FROM ' . $this->origen . ' m WHERE m.cabeza_id IN (' . $enLista . ') AND m.id <> m.cabeza_id ORDER BY m.id'
            );
            foreach ($mq->fetchAll() as $m) {
                $miembros[(int) $m['cabeza_id']][] = [
                    'id' => (int) $m['id'], 'nombre' => (string) $m['nombre'], 'rnc' => (string) $m['rnc'],
                    'tipo' => (string) $m['tipo'], 'ambiente' => (string) $m['ambiente'],
                ];
            }
        }
        return array_map(static function (array $r) use ($hoy, $miembros): array {
            $config = $r['cfg_client_id'] === null ? null : self::configJson([
                'client_id' => $r['cfg_client_id'], 'monto_mensual' => $r['cfg_monto'], 'dia_vencimiento' => $r['cfg_dia'],
                'descripcion' => $r['cfg_desc'], 'activo' => $r['cfg_activo'], 'updated_at' => $r['cfg_updated'], 'created_at' => $r['cfg_created'],
            ]);
            $masViejo = $r['mas_antiguo'] !== null ? (string) $r['mas_antiguo'] : null;
            return self::clienteJson($r) + [
                'tipo' => (string) $r['cl_tipo'], 'ambiente' => (string) $r['cl_ambiente'], 'activo' => (int) $r['cl_activo'] === 1,
                'con_config' => $config !== null, 'config' => $config,
                'excluido' => $r['ex_client_id'] !== null, 'motivo_exclusion' => $r['ex_motivo'] !== null ? (string) $r['ex_motivo'] : null,
                'saldo_pendiente' => CobroReglas::aNumero(CobroReglas::centavosDb($r['saldo_pendiente'])),
                'saldo_vencido' => CobroReglas::aNumero(CobroReglas::centavosDb($r['saldo_vencido'])),
                'cobros_abiertos' => (int) $r['cobros_abiertos'], 'cobros_vencidos' => (int) $r['cobros_vencidos'],
                'dias_atraso' => $masViejo !== null ? max(0, -CobroReglas::diasEntre($hoy, $masViejo)) : 0,
                'proximo_vencimiento' => $r['proximo'] !== null ? (string) $r['proximo'] : null,
                'facturable' => (int) $r['cl_facturable'] === 1,
                'motivo_oculto' => $r['ex_client_id'] !== null ? 'excluido' : ((int) $r['cl_facturable'] === 1 ? null : 'solo_certificacion'),
                // Pertenece a un grupo si tiene grupo_id, o si otras empresas lo tienen como cabeza (una cabeza sin su propio grupo_id).
                'grupo' => $r['cl_grupo_id'] !== null || isset($miembros[(int) $r['cl_id']])
                    ? ['cabeza_id' => (int) $r['cl_id'], 'miembros' => $miembros[(int) $r['cl_id']] ?? []]
                    : null,
            ];
        }, $filas);
    }

    /**
     * Quita a un tenant de la lista de clientes (excluir = true: no es un cliente al que se le cobra, p. ej. lo creo solo
     * para certificar) o lo devuelve a ella (false). NO borra nada: cobro_excluidos solo marca, y quitar la marca lo devuelve.
     * Solo existe en el ambito de tenants. Repetir lo mismo no cambia nada (repetido = true). Quitar de la lista a quien tiene
     * plata de por medio se rechaza con 409: con cobros abiertos (TIENE_COBROS_ABIERTOS) o con su cobro mensual activo
     * (CONFIG_ACTIVA); sus cobros ya pagados o cancelados no lo impiden. `anterior` es lo que habia, para la bitacora.
     *
     * @return array ['success', ['client_id' => int, 'excluido' => bool, 'motivo' => ?string, 'repetido' => bool, 'anterior' => array]]
     *               | ['error', ...]
     */
    public function excluirCliente(int $clientId, bool $excluir, ?string $motivo, ?int $userId): array
    {
        if ($this->ambito !== self::AMBITO_TENANTS) {
            return self::err(CobroReglas::MSG_RUTA, 404);
        }
        return $this->protegido(function () use ($clientId, $excluir, $motivo, $userId): array {
            if ($this->cliente($clientId) === null) {
                return self::err(CobroReglas::MSG_CLIENTE_NO_EXISTE, 404);
            }
            if (($cabeza = $this->cabezaDeGrupo($clientId)) !== null) {
                return self::errMiembroDeGrupo($cabeza);
            }
            // Lo de antes, SIN bloqueo (la fila puede no existir: un bloqueo de hueco entre dos sesiones daria deadlock). El
            // INSERT y el DELETE de abajo son atomicos por si mismos y su rowCount dice si de verdad cambiaron algo.
            $s = $this->db->prepare('SELECT motivo FROM cobro_excluidos WHERE client_id = :cid');
            $s->execute([':cid' => $clientId]);
            $fila = $s->fetch();
            $anterior = ['excluido' => $fila !== false, 'motivo' => $fila !== false && $fila['motivo'] !== null ? (string) $fila['motivo'] : null];
            if (!$excluir) {
                $d = $this->db->prepare('DELETE FROM cobro_excluidos WHERE client_id = :cid');
                $d->execute([':cid' => $clientId]);
                return ['success', ['client_id' => $clientId, 'excluido' => false, 'motivo' => null, 'repetido' => $d->rowCount() === 0, 'anterior' => $anterior]];
            }
            if ($fila !== false) {
                return ['success', ['client_id' => $clientId, 'excluido' => true, 'motivo' => $anterior['motivo'], 'repetido' => true, 'anterior' => $anterior]];
            }
            // No se quita de la lista a quien tiene plata de por medio: un cobro abierto o un cobro mensual que sigue generando.
            $a = $this->db->prepare('SELECT COUNT(*) FROM cobros c WHERE c.client_id = :cid AND ' . self::ABIERTO);
            $a->execute([':cid' => $clientId]);
            $abiertos = (int) $a->fetchColumn();
            if ($abiertos > 0) {
                return self::err(
                    'Esta empresa tiene ' . ($abiertos === 1 ? '1 cobro abierto: cóbralo o cancélalo' : $abiertos . ' cobros abiertos: cóbralos o cancélalos')
                        . ' antes de quitarla de la lista de clientes.',
                    409, 'TIENE_COBROS_ABIERTOS', ['cobros_abiertos' => $abiertos]
                );
            }
            $config = $this->filaConfig($clientId, false);
            if ($config !== null && (int) $config['activo'] === 1) {
                return self::err('Esta empresa tiene el cobro mensual activo: desactívalo antes de quitarla de la lista de clientes.', 409, 'CONFIG_ACTIVA');
            }
            $i = $this->db->prepare(
                'INSERT INTO cobro_excluidos (client_id, motivo, user_id, created_at) VALUES (:cid, :motivo, :user, :ahora) '
                . ($this->mysql ? 'ON DUPLICATE KEY UPDATE client_id = client_id' : 'ON CONFLICT (client_id) DO NOTHING')
            );
            $i->execute([':cid' => $clientId, ':motivo' => $motivo, ':user' => $userId, ':ahora' => $this->ahora()]);
            return ['success', ['client_id' => $clientId, 'excluido' => true, 'motivo' => $motivo, 'repetido' => $i->rowCount() === 0, 'anterior' => $anterior]];
        }, 'No se pudo cambiar si la empresa es un cliente. Inténtalo de nuevo.');
    }

    /** La configuracion mensual del cliente, tal cual la ve la API; null si no tiene. */
    public function obtenerConfig(int $clientId): ?array
    {
        $f = $this->filaConfig($clientId, false);
        return $f === null ? null : self::configJson($f);
    }

    /**
     * Crea o cambia la configuracion mensual de un cliente. NO toca ningun cobro ya
     * generado: cada cobro guarda su propio monto, y el nuevo monto solo vale para los que
     * se generen despues.
     *
     * @param array{monto_c:int, dia:int, descripcion:?string, activo:bool} $c de CobroReglas::validarConfig
     * @return array ['success', ['config' => ..., 'anterior' => ...|null]] | ['error', ...]
     */
    public function guardarConfig(int $clientId, array $c, ?int $userId): array
    {
        return $this->protegido(function () use ($clientId, $c, $userId): array {
            if ($this->cliente($clientId) === null) {
                return self::err(CobroReglas::MSG_CLIENTE_NO_EXISTE, 404);
            }
            if (($cabeza = $this->cabezaDeGrupo($clientId)) !== null) {
                return self::errMiembroDeGrupo($cabeza);
            }
            // Lo de antes, SIN bloqueo: solo es el old_values de la bitacora. No se bloquea (SELECT ...
            // FOR UPDATE) porque la fila puede no existir todavia: en InnoDB eso toma un bloqueo de
            // hueco que dos sesiones pueden tener a la vez, y sus dos INSERT se esperarian entre si
            // (deadlock 1213). Un solo upsert atomico crea o cambia la fila sin esa carrera.
            $anterior = $this->filaConfig($clientId, false);
            $ahora = $this->ahora();
            $monto = CobroReglas::aDecimal($c['monto_c']);
            $v = [':cid' => $clientId, ':monto' => $monto, ':dia' => $c['dia'], ':desc' => $c['descripcion'],
                ':activo' => $c['activo'] ? 1 : 0, ':user' => $userId, ':ahora' => $ahora];
            // Los mismos valores para la rama de "ya existia", con otros nombres: PDO no deja repetir uno.
            foreach (['monto', 'dia', 'desc', 'activo', 'user', 'ahora'] as $k) {
                $v[':' . $k . '2'] = $v[':' . $k];
            }
            $this->db->prepare(
                'INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, descripcion, activo, user_id, created_at)
                 VALUES (:cid, :monto, :dia, :desc, :activo, :user, :ahora) '
                . ($this->mysql ? 'ON DUPLICATE KEY UPDATE ' : 'ON CONFLICT (client_id) DO UPDATE SET ')
                . 'monto_mensual = :monto2, dia_vencimiento = :dia2, descripcion = :desc2, activo = :activo2, user_id = :user2, updated_at = :ahora2'
            )->execute($v);
            return ['success', [
                'config' => self::configJson($this->filaConfig($clientId, false) ?? []),
                'anterior' => $anterior !== null ? self::configJson($anterior) : null,
            ]];
        }, self::MSG_CONFIG);
    }

    // ========================================================================
    // Cobros: lectura
    // ========================================================================

    /**
     * Una pagina de cobros con los filtros de CobroReglas::validarFiltros.
     * @return list<array>
     */
    public function listar(array $f, int $offset, int $limit): array
    {
        $hoy = $this->hoy();
        $params = [];
        $where = $this->filtrar($f, $hoy, $params);
        $sql = 'SELECT c.*, ' . self::CLIENTE . ',
                    (SELECT MAX(p.fecha_pago) FROM cobro_pagos p WHERE p.cobro_id = c.id AND p.anulado_at IS NULL) AS ultimo_pago,
                    (SELECT MAX(r.fecha_hora) FROM cobro_recordatorios r WHERE r.cobro_id = c.id) AS ultimo_recordatorio,
                    (SELECT COUNT(*) FROM cobro_recordatorios r WHERE r.cobro_id = c.id) AS recordatorios
                  FROM cobros c JOIN ' . $this->origen . ' cl ON cl.id = c.client_id
                 WHERE ' . $where . '
                 ORDER BY ' . self::orden($f['orden'] ?? 'urgencia') . '
                 LIMIT :limite OFFSET :desplazamiento';
        $s = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $s->bindValue($k, $v);
        }
        $s->bindValue(':limite', $limit, PDO::PARAM_INT);
        $s->bindValue(':desplazamiento', $offset, PDO::PARAM_INT);
        $s->execute();
        return array_map(fn(array $r): array => $this->cobroJson($r, $hoy), $s->fetchAll());
    }

    /** Cuantos cobros cumplen los filtros (el total de la paginacion). */
    public function contar(array $f): int
    {
        $params = [];
        $where = $this->filtrar($f, $this->hoy(), $params);
        $s = $this->db->prepare('SELECT COUNT(*) FROM cobros c JOIN ' . $this->origen . ' cl ON cl.id = c.client_id WHERE ' . $where);
        $s->execute($params);
        return (int) $s->fetchColumn();
    }

    /** Un cobro con sus pagos (vigentes y anulados) y sus recordatorios, o null si no existe. */
    public function obtener(int $id): ?array
    {
        $hoy = $this->hoy();
        $f = $this->filaCobroCompleta($id);
        if ($f === null) {
            return null;
        }
        $cobro = $this->cobroJson($f, $hoy);
        $cobro['pagos'] = $this->pagosDe([$id])[$id] ?? [];
        $cobro['recordatorios_lista'] = $this->recordatoriosDe([$id])[$id] ?? [];
        $s = $this->db->prepare('SELECT id, periodo FROM cobros WHERE generado_de_cobro_id = :id ORDER BY id');
        $s->execute([':id' => $id]);
        $cobro['genero'] = array_map(
            static fn(array $g): array => ['id' => (int) $g['id'], 'periodo' => (string) $g['periodo']],
            $s->fetchAll()
        );
        return $cobro;
    }

    /**
     * Lo que muestra el panel de un cliente: sus datos, su configuracion mensual, el resumen
     * de lo que debe, TODOS sus cobros con sus pagos, sus recordatorios y lo que se le
     * sugiere para el proximo cobro. null si el cliente no existe.
     */
    public function panelCliente(int $clientId): ?array
    {
        $cliente = $this->cliente($clientId);
        if ($cliente === null) {
            return null;
        }
        $hoy = $this->hoy();
        $s = $this->db->prepare(
            'SELECT c.*, ' . self::CLIENTE . ' FROM cobros c JOIN ' . $this->origen . ' cl ON cl.id = c.client_id
              WHERE c.client_id = :cid ORDER BY c.periodo DESC, c.id DESC LIMIT 240'
        );
        $s->execute([':cid' => $clientId]);
        $filas = $s->fetchAll();
        $ids = array_map(static fn(array $f): int => (int) $f['id'], $filas);
        $pagos = $this->pagosDe($ids);
        $recordatorios = $this->recordatoriosDe($ids);
        $cobros = [];
        $resumen = [
            'saldo_pendiente' => 0, 'saldo_vencido' => 0, 'cobros_abiertos' => 0, 'cobros_vencidos' => 0,
            'total_cobrado' => 0, 'total_facturado' => 0,
        ];
        $proximo = null;
        $masViejo = null;
        foreach ($filas as $f) {
            $c = $this->cobroJson($f, $hoy);
            $c['pagos'] = $pagos[(int) $f['id']] ?? [];
            $cobros[] = $c;
            $saldoC = CobroReglas::centavosDb($f['monto']) - CobroReglas::centavosDb($f['monto_pagado']);
            if ($f['cancelado_at'] === null) {
                $resumen['total_facturado'] += CobroReglas::centavosDb($f['monto']);
                $resumen['total_cobrado'] += CobroReglas::centavosDb($f['monto_pagado']);
            }
            if ($f['cancelado_at'] === null && $saldoC > 0) {
                $resumen['saldo_pendiente'] += $saldoC;
                $resumen['cobros_abiertos']++;
                if ((string) $f['fecha_vencimiento'] < $hoy) {
                    $resumen['saldo_vencido'] += $saldoC;
                    $resumen['cobros_vencidos']++;
                    $masViejo = $masViejo === null || (string) $f['fecha_vencimiento'] < $masViejo ? (string) $f['fecha_vencimiento'] : $masViejo;
                } elseif ($proximo === null || (string) $f['fecha_vencimiento'] < $proximo['fecha_vencimiento']) {
                    $proximo = ['cobro_id' => (int) $f['id'], 'periodo' => (string) $f['periodo'],
                        'fecha_vencimiento' => (string) $f['fecha_vencimiento'], 'saldo' => CobroReglas::aNumero($saldoC)];
                }
            }
        }
        $config = $this->obtenerConfig($clientId);
        $recs = [];
        foreach ($recordatorios as $lista) {
            foreach ($lista as $r) {
                $recs[] = $r;
            }
        }
        usort($recs, static fn(array $a, array $b): int => [$b['fecha_hora'], $b['id']] <=> [$a['fecha_hora'], $a['id']]);
        foreach (['saldo_pendiente', 'saldo_vencido', 'total_cobrado', 'total_facturado'] as $k) {
            $resumen[$k] = CobroReglas::aNumero($resumen[$k]);
        }
        $resumen['proximo_vencimiento'] = $proximo;
        $resumen['vencido_desde'] = $masViejo;
        return [
            'cliente' => $cliente,
            'config' => $config,
            'resumen' => $resumen,
            'sugerido' => $this->sugerido($clientId, $config, $hoy),
            'cobros' => $cobros,
            'recordatorios' => array_slice($recs, 0, 100),
        ];
    }

    /**
     * Lo que se le sugiere al crear un cobro a mano a este cliente: el periodo (el mes que
     * sigue a su ultimo cobro, nunca uno anterior al de hoy), y con su configuracion, el
     * monto, el vencimiento y la descripcion. Sin configuracion solo trae el periodo.
     */
    public function sugerido(int $clientId, ?array $config, string $hoy): array
    {
        $periodo = $this->periodoSugerido($clientId, $hoy);
        return [
            'periodo' => $periodo,
            'monto' => $config['monto_mensual'] ?? null,
            'fecha_vencimiento' => $config !== null ? CobroReglas::vencimientoDe($periodo, (int) $config['dia_vencimiento']) : null,
            'descripcion' => $config['descripcion'] ?? null,
        ];
    }

    /**
     * El dashboard compacto. $dias = ventana de "vence pronto".
     * Montos: pendiente = saldo de todo lo abierto; vencido = lo abierto que vencio antes de
     * hoy; por_vencer = lo abierto que vence en los proximos $dias dias (hoy incluido);
     * cobrado_mes = pagos vigentes con fecha dentro del mes de hoy; por_cobrar_mes = saldo de
     * lo abierto que vence dentro del mes de hoy; facturado_mes = monto de los cobros no
     * cancelados que vencen en el mes.
     */
    public function resumen(int $dias = CobroReglas::DIAS_PROXIMOS): array
    {
        $hoy = $this->hoy();
        [$mesIni, $mesFin] = CobroReglas::limitesDelMes($hoy);
        $hasta = CobroReglas::sumarDias($hoy, $dias);
        $a = self::ABIERTO;
        $saldo = self::SALDO;

        $s = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN {$a} THEN {$saldo} END), 0) AS pendiente,
                COALESCE(SUM(CASE WHEN {$a} AND c.fecha_vencimiento < :hoy1 THEN {$saldo} END), 0) AS vencido,
                COALESCE(SUM(CASE WHEN {$a} AND c.fecha_vencimiento >= :hoy2 AND c.fecha_vencimiento <= :hasta THEN {$saldo} END), 0) AS por_vencer,
                COALESCE(SUM(CASE WHEN {$a} AND c.fecha_vencimiento >= :mes_ini1 AND c.fecha_vencimiento <= :mes_fin1 THEN {$saldo} END), 0) AS por_cobrar_mes,
                COALESCE(SUM(CASE WHEN c.cancelado_at IS NULL AND c.fecha_vencimiento >= :mes_ini2 AND c.fecha_vencimiento <= :mes_fin2 THEN c.monto END), 0) AS facturado_mes,
                COUNT(CASE WHEN {$a} THEN 1 END) AS cobros_abiertos,
                COUNT(CASE WHEN {$a} AND c.fecha_vencimiento < :hoy3 THEN 1 END) AS cobros_vencidos,
                COUNT(DISTINCT CASE WHEN {$a} THEN c.client_id END) AS clientes_con_saldo,
                COUNT(DISTINCT CASE WHEN {$a} AND c.fecha_vencimiento < :hoy4 THEN c.client_id END) AS clientes_con_atraso
               FROM cobros c"
        );
        $s->execute([
            ':hoy1' => $hoy, ':hoy2' => $hoy, ':hoy3' => $hoy, ':hoy4' => $hoy, ':hasta' => $hasta,
            ':mes_ini1' => $mesIni, ':mes_fin1' => $mesFin, ':mes_ini2' => $mesIni, ':mes_fin2' => $mesFin,
        ]);
        $t = $s->fetch() ?: [];

        $s = $this->db->prepare(
            'SELECT COALESCE(SUM(p.monto), 0) FROM cobro_pagos p
              WHERE p.anulado_at IS NULL AND p.fecha_pago >= :ini AND p.fecha_pago <= :fin'
        );
        $s->execute([':ini' => $mesIni, ':fin' => $mesFin]);
        $cobradoMes = CobroReglas::centavosDb($s->fetchColumn());

        $money = static fn(string $k): float => CobroReglas::aNumero(CobroReglas::centavosDb($t[$k] ?? 0));
        $totales = [
            'pendiente' => $money('pendiente'),
            'vencido' => $money('vencido'),
            'por_vencer' => $money('por_vencer'),
            'cobrado_mes' => CobroReglas::aNumero($cobradoMes),
            'por_cobrar_mes' => $money('por_cobrar_mes'),
            'facturado_mes' => $money('facturado_mes'),
            'cobros_abiertos' => (int) ($t['cobros_abiertos'] ?? 0),
            'cobros_vencidos' => (int) ($t['cobros_vencidos'] ?? 0),
            'clientes_con_saldo' => (int) ($t['clientes_con_saldo'] ?? 0),
            'clientes_con_atraso' => (int) ($t['clientes_con_atraso'] ?? 0),
        ];

        // Antiguedad de lo abierto: vigente (aun no vence) y 1-30, 31-60, 61-90 y mas de 90 dias de atraso.
        $s = $this->db->prepare(
            "SELECT CASE WHEN c.fecha_vencimiento >= :hoy THEN 'vigente'
                         WHEN c.fecha_vencimiento >= :d30 THEN '1-30'
                         WHEN c.fecha_vencimiento >= :d60 THEN '31-60'
                         WHEN c.fecha_vencimiento >= :d90 THEN '61-90'
                         ELSE '90+' END AS tramo,
                    COUNT(*) AS cobros, COALESCE(SUM({$saldo}), 0) AS monto
               FROM cobros c WHERE {$a} GROUP BY tramo"
        );
        $s->execute([
            ':hoy' => $hoy, ':d30' => CobroReglas::sumarDias($hoy, -30), ':d60' => CobroReglas::sumarDias($hoy, -60),
            ':d90' => CobroReglas::sumarDias($hoy, -90),
        ]);
        $porTramo = [];
        foreach ($s->fetchAll() as $r) {
            $porTramo[(string) $r['tramo']] = $r;
        }
        $antiguedad = [];
        foreach (['vigente' => 'Vigente', '1-30' => '1 a 30 días', '31-60' => '31 a 60 días', '61-90' => '61 a 90 días', '90+' => 'Más de 90 días'] as $clave => $etiqueta) {
            $r = $porTramo[$clave] ?? null;
            $antiguedad[] = [
                'tramo' => $clave, 'etiqueta' => $etiqueta,
                'cobros' => $r !== null ? (int) $r['cobros'] : 0,
                'monto' => $r !== null ? CobroReglas::aNumero(CobroReglas::centavosDb($r['monto'])) : 0.0,
            ];
        }

        // Lo que vence pronto, lo mas cercano primero.
        $params = [];
        $where = $this->filtrar(['estado' => null, 'client_id' => null, 'periodo' => null, 'desde' => $hoy, 'hasta' => $hasta, 'query' => null], $hoy, $params, true);
        $s = $this->db->prepare(
            'SELECT c.*, ' . self::CLIENTE . ' FROM cobros c JOIN ' . $this->origen . ' cl ON cl.id = c.client_id
              WHERE ' . $where . ' ORDER BY c.fecha_vencimiento ASC, c.id ASC LIMIT 8'
        );
        $s->execute($params);
        $proximos = array_map(fn(array $r): array => $this->cobroJson($r, $hoy), $s->fetchAll());

        // Clientes con pagos atrasados, los que mas deben primero.
        $s = $this->db->prepare(
            "SELECT c.client_id, cl.client_name AS cl_contacto, cl.company_name AS cl_empresa, cl.razon_social AS cl_razon,
                    cl.rnc AS cl_rnc, cl.email AS cl_email, cl.phone_number AS cl_tel, cl.id AS cl_id,
                    COUNT(*) AS cobros_vencidos, COALESCE(SUM({$saldo}), 0) AS saldo_vencido,
                    MIN(c.fecha_vencimiento) AS mas_antiguo
               FROM cobros c JOIN {$this->origen} cl ON cl.id = c.client_id
              WHERE {$a} AND c.fecha_vencimiento < :hoy
              GROUP BY c.client_id, cl.id, cl.client_name, cl.company_name, cl.razon_social, cl.rnc, cl.email, cl.phone_number
              ORDER BY saldo_vencido DESC, mas_antiguo ASC
              LIMIT 8"
        );
        $s->execute([':hoy' => $hoy]);
        $atrasados = [];
        foreach ($s->fetchAll() as $r) {
            $atrasados[] = [
                'client_id' => (int) $r['client_id'],
                'cliente' => self::clienteJson($r),
                'cobros_vencidos' => (int) $r['cobros_vencidos'],
                'saldo_vencido' => CobroReglas::aNumero(CobroReglas::centavosDb($r['saldo_vencido'])),
                'mas_antiguo' => (string) $r['mas_antiguo'],
                'dias_atraso' => max(0, -CobroReglas::diasEntre($hoy, (string) $r['mas_antiguo'])),
            ];
        }

        return [
            'hoy' => $hoy, 'ahora' => $this->ahora(), 'mes' => CobroReglas::periodoDe($hoy), 'dias' => $dias,
            'totales' => $totales, 'antiguedad' => $antiguedad, 'proximos' => $proximos, 'atrasados' => $atrasados,
        ];
    }

    /**
     * A quien recordarle: los CLIENTES con algo vencido o por vencer (dentro de $dias dias) y los
     * cobros que lo explican. Es INFORMATIVO: la app no envia nada.
     *
     * 'clientes' tiene una fila por cliente (lo que vencio, lo que vence pronto, su ultimo
     * recordatorio en CUALQUIERA de sus cobros, tambien en los ya pagados, y si ya toca otro, con
     * $cada dias entre uno y otro);
     * los que requieren uno, primero, y dentro de cada grupo el mas atrasado. 'items' son los
     * cobros, cada uno con SU ultimo recordatorio ('ultimo_recordatorio') y el del cliente ('ultimo');
     * su 'requiere_recordatorio' usa el del cliente (CobroReglas::requiereRecordatorio), asi un
     * cliente "recordado" no tiene cobros que digan "por recordar".
     *
     * Mira como maximo CobroReglas::MAX_FILAS_RECORDATORIOS cobros (los mas atrasados primero);
     * 'truncado' dice si habia mas, para que la pantalla no presente los totales como completos.
     */
    public function recordatorios(int $dias = CobroReglas::DIAS_PROXIMOS, int $cada = CobroReglas::DIAS_RECORDAR): array
    {
        $hoy = $this->hoy();
        $hasta = CobroReglas::sumarDias($hoy, $dias);
        $tope = CobroReglas::MAX_FILAS_RECORDATORIOS;
        $s = $this->db->prepare(
            'SELECT c.*, ' . self::CLIENTE . ' FROM cobros c JOIN ' . $this->origen . ' cl ON cl.id = c.client_id
              WHERE ' . self::ABIERTO . ' AND c.fecha_vencimiento <= :hasta
              ORDER BY c.fecha_vencimiento ASC, c.id ASC LIMIT ' . ($tope + 1)
        );
        $s->execute([':hasta' => $hasta]);
        $filas = $s->fetchAll();
        $truncado = count($filas) > $tope;
        $filas = array_slice($filas, 0, $tope);
        $ids = array_map(static fn(array $f): int => (int) $f['id'], $filas);
        $lista = $this->recordatoriosDe($ids);   // por cobro, del mas nuevo al mas viejo

        // Lo de cada CLIENTE: su ultimo recordatorio y cuantos tiene, en cualquiera de sus cobros. Tambien en los
        // ya pagados: una llamada al cliente sigue contando aunque el cobro por el que se hizo ya se haya cobrado
        // (si no, pagar ese cobro dejaria al cliente "sin recordar" y habria que llamarlo otra vez).
        $deCliente = $this->recordatoriosPorCliente(
            array_values(array_unique(array_map(static fn(array $f): int => (int) $f['client_id'], $filas)))
        );

        $items = [];
        $clientes = [];
        $res = [
            'por_recordar' => 0, 'recordados' => 0, 'vencidos' => 0, 'por_vencer' => 0,
            'clientes' => 0, 'clientes_por_recordar' => 0, 'clientes_recordados' => 0,
        ];
        foreach ($filas as $f) {
            $id = (int) $f['id'];
            $cid = (int) $f['client_id'];
            $hechos = $lista[$id] ?? [];
            $f['recordatorios'] = count($hechos);
            $f['ultimo_recordatorio'] = $hechos[0]['fecha_hora'] ?? null;
            $c = $this->cobroJson($f, $hoy);
            $ultimoCliente = $deCliente[$cid]['ultimo'] ?? null;
            $requiere = CobroReglas::requiereRecordatorio(
                $c['estado'], $c['fecha_vencimiento'], $ultimoCliente['fecha_hora'] ?? null, $hoy, $cada
            );
            $vencido = $c['estado'] === CobroReglas::VENCIDO;
            $c['requiere_recordatorio'] = $requiere;
            $c['motivo'] = $vencido ? 'vencido' : 'por_vencer';
            $c['ultimo'] = $ultimoCliente;
            $items[] = $c;
            $res[$requiere ? 'por_recordar' : 'recordados']++;
            $res[$vencido ? 'vencidos' : 'por_vencer']++;

            // Las filas vienen por vencimiento ascendente: el primer cobro de un cliente es el mas urgente
            // (donde se anota el recordatorio) y el primero vencido / por vencer es el mas antiguo / el mas cercano.
            if (!isset($clientes[$cid])) {
                $clientes[$cid] = [
                    'client_id' => $cid, 'cliente' => $c['cliente'], 'motivo' => 'por_vencer',
                    'cobros_vencidos' => 0, 'cobros_por_vencer' => 0, 'saldo_vencido_c' => 0, 'saldo_por_vencer_c' => 0,
                    'mas_antiguo' => null, 'proximo_vencimiento' => null, 'recordatorios' => $deCliente[$cid]['total'] ?? 0,
                    'ultimo' => $ultimoCliente, 'requiere_recordatorio' => false,
                    'cobro_id' => $id, 'periodo' => $c['periodo'],
                ];
            }
            $k = &$clientes[$cid];
            $saldoC = CobroReglas::centavosDb($f['monto']) - CobroReglas::centavosDb($f['monto_pagado']);
            $k['requiere_recordatorio'] = $k['requiere_recordatorio'] || $requiere;
            if ($vencido) {
                $k['motivo'] = 'vencido';
                $k['cobros_vencidos']++;
                $k['saldo_vencido_c'] += $saldoC;
                $k['mas_antiguo'] ??= $c['fecha_vencimiento'];
            } else {
                $k['cobros_por_vencer']++;
                $k['saldo_por_vencer_c'] += $saldoC;
                $k['proximo_vencimiento'] ??= $c['fecha_vencimiento'];
            }
            unset($k);
        }

        foreach ($clientes as &$k) {
            $k['saldo_vencido'] = CobroReglas::aNumero($k['saldo_vencido_c']);
            $k['saldo_por_vencer'] = CobroReglas::aNumero($k['saldo_por_vencer_c']);
            unset($k['saldo_vencido_c'], $k['saldo_por_vencer_c']);
            $k['dias_atraso'] = $k['mas_antiguo'] !== null ? max(0, -CobroReglas::diasEntre($hoy, $k['mas_antiguo'])) : 0;
            $res['clientes']++;
            $res[$k['requiere_recordatorio'] ? 'clientes_por_recordar' : 'clientes_recordados']++;
        }
        unset($k);

        // Primero los que hay que recordar; dentro de cada grupo, los mas atrasados (o los que vencen antes).
        $clientes = array_values($clientes);
        $sinFecha = '9999-12-31';
        usort($clientes, static fn(array $a, array $b): int =>
            [$b['requiere_recordatorio'], $a['mas_antiguo'] ?? $sinFecha, $a['proximo_vencimiento'] ?? $sinFecha, $a['client_id']]
            <=> [$a['requiere_recordatorio'], $b['mas_antiguo'] ?? $sinFecha, $b['proximo_vencimiento'] ?? $sinFecha, $b['client_id']]);
        usort($items, static fn(array $a, array $b): int =>
            [$b['requiere_recordatorio'], $a['fecha_vencimiento'], $a['id']] <=> [$a['requiere_recordatorio'], $b['fecha_vencimiento'], $b['id']]);
        return [
            'hoy' => $hoy, 'ahora' => $this->ahora(), 'dias' => $dias, 'cada' => $cada, 'truncado' => $truncado,
            'resumen' => $res, 'clientes' => $clientes, 'items' => $items,
        ];
    }

    // ========================================================================
    // Cobros: escritura
    // ========================================================================

    /**
     * Crea un cobro a mano. $d sale de CobroReglas::validarNuevo y se completa aqui con la
     * configuracion del cliente (CobroReglas::resolverNuevo).
     * Un cobro del mismo cliente y periodo ya existe -> 409 COBRO_DUPLICADO (con su id).
     *
     * @return array ['success', cobro] | ['error', texto, http, codigo?, data?]
     */
    public function crear(array $d, ?int $userId): array
    {
        return $this->protegido(function () use ($d, $userId): array {
            $clientId = (int) $d['client_id'];
            if ($this->cliente($clientId) === null) {
                return self::err(CobroReglas::MSG_CLIENTE_NO_EXISTE, 404);
            }
            if (($cabeza = $this->cabezaDeGrupo($clientId)) !== null) {
                return self::errMiembroDeGrupo($cabeza);
            }
            $config = $this->configInterna($clientId);
            $hoy = $this->hoy();
            $r = CobroReglas::resolverNuevo($d, $config, $this->periodoSugerido($clientId, $hoy));
            if ($r[0] !== 'success') {
                return $r;
            }
            $c = $r[1];
            if ($c['monto_c'] > CobroReglas::MAX_CENTAVOS) {
                return self::err(CobroReglas::MSG_MONTO_GRANDE, 422);
            }
            $nuevo = $this->insertarCobro($clientId, $c['periodo'], $c['descripcion'], $c['monto_c'], $c['fecha'], null, $userId);
            if ($nuevo === null) {
                return $this->duplicado($clientId, $c['periodo']);
            }
            return ['success', $this->obtener($nuevo)];
        }, self::MSG_GUARDAR);
    }

    /**
     * Cambia el monto, el vencimiento o la descripcion de un cobro. El periodo no cambia. El monto no puede quedar por debajo de lo
     * ya pagado. Si el cambio deja el cobro pagado completo, se genera el del mes siguiente
     * igual que con un pago.
     *
     * @param array $d de CobroReglas::validarCambios
     * @return array ['success', ['cobro' => ..., 'siguiente' => ...|null, 'siguiente_motivo' => ...|null, 'anterior' => ...]] | ['error', ...]
     */
    public function actualizar(int $id, array $d, ?int $userId): array
    {
        return $this->protegido(function () use ($id, $d, $userId): array {
            $c = $this->filaCobro($id, true);
            if ($c === null) {
                return self::err(CobroReglas::MSG_NO_EXISTE, 404);
            }
            if ($c['cancelado_at'] !== null) {
                return self::err(CobroReglas::MSG_CANCELADO, 409, 'COBRO_CANCELADO');
            }
            if (isset($d['periodo']) && $d['periodo'] !== (string) $c['periodo']) {
                return self::err(CobroReglas::MSG_PERIODO_FIJO, 422);
            }
            $anterior = $this->obtener($id);
            $pagadoC = CobroReglas::centavosDb($c['monto_pagado']);
            $montoAntesC = CobroReglas::centavosDb($c['monto']);
            $montoC = $d['monto_c'] ?? $montoAntesC;
            if ($montoC < $pagadoC) {
                return self::err('El monto no puede ser menor a lo ya pagado (' . CobroReglas::texto($pagadoC) . ').', 422, 'MONTO_MENOR_PAGADO');
            }
            $this->db->prepare(
                'UPDATE cobros SET monto = :monto, fecha_vencimiento = :fecha, descripcion = :desc, updated_at = :ahora WHERE id = :id'
            )->execute([
                ':monto' => CobroReglas::aDecimal($montoC),
                ':fecha' => $d['fecha'] ?? (string) $c['fecha_vencimiento'],
                ':desc' => array_key_exists('descripcion', $d) ? $d['descripcion'] : $c['descripcion'],
                ':ahora' => $this->ahora(),
                ':id' => $id,
            ]);
            // Solo si ESTE cambio lo deja pagado completo (antes debia algo, ahora no): editar la
            // descripcion de un cobro que ya estaba pagado no genera nada por su cuenta.
            $gen = ['cobro' => null, 'motivo' => null];
            if ($montoAntesC - $pagadoC > 0 && $montoC - $pagadoC <= 0) {
                $gen = $this->generarSiguiente($this->filaCobro($id, false), $userId);
            }
            return ['success', [
                'cobro' => $this->obtener($id), 'siguiente' => $gen['cobro'], 'siguiente_motivo' => $gen['motivo'], 'anterior' => $anterior,
            ]];
        }, self::MSG_GUARDAR);
    }

    /**
     * Registra un pago (completo o parcial) y deja todo consistente en UNA transaccion:
     * el pago, el monto pagado del cobro y, si el cobro queda pagado completo, el cobro del
     * mes siguiente.
     *
     * Idempotente: con la misma clave (idempotency_key, el UUID del formulario) y los MISMOS datos
     * devuelve el pago ya registrado ('repetido' => true) en vez de registrar otro, y con el mismo
     * siguiente cobro. Una clave que ya es de OTRO cobro, o que llega con otro monto, fecha o
     * metodo, o cuyo pago ya se anulo, da 409: una repeticion de verdad no cambia de datos, y
     * contestarle "ya estaba registrado" a otra operacion enganaria a quien la mando.
     *
     * 'siguiente_motivo' dice que paso con el cobro del mes siguiente cuando ESTE pago completo el
     * cobro: generado | sin_config | inactivo | ya_existia (null si el pago no lo completo).
     *
     * @param array{monto_c:int, fecha_pago:string, metodo:string, observaciones:?string, clave:?string} $p de CobroReglas::validarPago
     * @return array ['success', ['pago'=>..., 'cobro'=>..., 'siguiente'=>...|null, 'siguiente_nuevo'=>bool, 'siguiente_motivo'=>...|null, 'repetido'=>bool]] | ['error', ...]
     */
    public function registrarPago(int $cobroId, array $p, ?int $userId): array
    {
        return $this->protegido(function () use ($cobroId, $p, $userId): array {
            $c = $this->filaCobro($cobroId, true);
            if ($c === null) {
                return self::err(CobroReglas::MSG_NO_EXISTE, 404);
            }
            if ($p['clave'] !== null) {
                $previo = $this->pagoPorClave($p['clave']);
                if ($previo !== null) {
                    return $this->repeticion($previo, $cobroId, $p);
                }
            }
            if ($c['cancelado_at'] !== null) {
                return self::err(CobroReglas::MSG_CANCELADO, 409, 'COBRO_CANCELADO');
            }
            $saldoC = CobroReglas::centavosDb($c['monto']) - CobroReglas::centavosDb($c['monto_pagado']);
            if ($saldoC <= 0) {
                return self::err(CobroReglas::MSG_YA_PAGADO, 409, 'YA_PAGADO');
            }
            if ($this->contarDe('cobro_pagos', $cobroId) >= CobroReglas::MAX_PAGOS) {
                return self::err(self::MSG_LIMITE_PAGOS, 409, 'LIMITE_PAGOS');
            }
            if ($p['monto_c'] > $saldoC) {
                return self::err(
                    'El pago (' . CobroReglas::texto($p['monto_c']) . ') es mayor que el saldo pendiente (' . CobroReglas::texto($saldoC) . '). Escribe ese saldo o menos.',
                    422,
                    'EXCEDE_SALDO',
                    ['saldo' => CobroReglas::aNumero($saldoC)]
                );
            }
            try {
                $this->db->prepare(
                    'INSERT INTO cobro_pagos (cobro_id, monto, fecha_pago, metodo, observaciones, idempotency_key, user_id, created_at)
                     VALUES (:cobro, :monto, :fecha, :metodo, :obs, :clave, :user, :ahora)'
                )->execute([
                    ':cobro' => $cobroId, ':monto' => CobroReglas::aDecimal($p['monto_c']), ':fecha' => $p['fecha_pago'],
                    ':metodo' => $p['metodo'], ':obs' => $p['observaciones'], ':clave' => $p['clave'], ':user' => $userId,
                    ':ahora' => $this->ahora(),
                ]);
            } catch (PDOException $e) {
                if (self::esDuplicado($e, ['uk_cobro_pagos_idem', 'cobro_pagos.idempotency_key'])) {
                    return self::err(self::MSG_CLAVE_USADA, 409, 'CLAVE_REPETIDA');
                }
                throw $e;
            }
            $pagoId = (int) $this->db->lastInsertId();
            $this->recalcular($cobroId);
            $actual = $this->filaCobro($cobroId, false);
            $gen = ['cobro' => null, 'motivo' => null];
            if (CobroReglas::centavosDb($actual['monto']) - CobroReglas::centavosDb($actual['monto_pagado']) <= 0) {
                $gen = $this->generarSiguiente($actual, $userId);
            }
            $r = $this->resultadoPago($this->filaPago($pagoId) ?? [], $cobroId, false);
            // 'siguiente' es el cobro que ESTE cobro generó (ahora o antes); 'siguiente_nuevo' dice
            // si se generó en esta petición, para que la pantalla lo anuncie una sola vez, y
            // 'siguiente_motivo' por qué no, cuando el pago lo completó y no se generó nada.
            $r['siguiente'] = $gen['cobro'] ?? $r['siguiente'];
            $r['siguiente_nuevo'] = $gen['cobro'] !== null;
            $r['siguiente_motivo'] = $gen['motivo'];
            return ['success', $r];
        }, self::MSG_PAGAR);
    }

    /**
     * Anula un pago: deja de contar en el monto pagado del cobro, pero se queda a la vista
     * (anulado_at, motivo). Anular dos veces es lo mismo que una ('repetido' => true). El
     * siguiente cobro, si ya se genero, se queda: es un cobro del mes siguiente que ya existe.
     *
     * @return array ['success', ['pago'=>..., 'cobro'=>..., 'repetido'=>bool]] | ['error', ...]
     */
    public function anularPago(int $pagoId, ?string $motivo, ?int $userId): array
    {
        return $this->protegido(function () use ($pagoId, $motivo, $userId): array {
            $previo = $this->filaPago($pagoId);
            if ($previo === null) {
                return self::err(CobroReglas::MSG_PAGO_NO_EXISTE, 404);
            }
            $cobroId = (int) $previo['cobro_id'];
            // El cobro se bloquea ANTES de leer el pago otra vez: si otra peticion lo anula
            // a la vez, esta espera y ve el resultado (con la lectura bloqueante de abajo).
            $this->filaCobro($cobroId, true);
            // Con FOR UPDATE: una lectura "actual". La plana usaria el snapshot (REPEATABLE READ) que nacio
            // con la primera lectura de arriba, ANTES de esperar el bloqueo, y no veria que la otra peticion
            // ya anulo este pago (dos "Anular" a la vez dejaban dos filas de bitacora y un pago "no anulado").
            $pago = $this->filaPago($pagoId, true);
            if ($pago === null) {
                return self::err(CobroReglas::MSG_PAGO_NO_EXISTE, 404);
            }
            $repetido = $pago['anulado_at'] !== null;
            if (!$repetido) {
                $this->db->prepare('UPDATE cobro_pagos SET anulado_at = :ahora, motivo_anulacion = :motivo WHERE id = :id AND anulado_at IS NULL')
                    ->execute([':ahora' => $this->ahora(), ':motivo' => $motivo, ':id' => $pagoId]);
                $this->recalcular($cobroId);
            }
            return ['success', [
                'pago' => self::pagoJson($this->filaPago($pagoId) ?? []),
                'cobro' => $this->obtener($cobroId),
                'anterior' => self::pagoJson($pago),
                'repetido' => $repetido,
            ]];
        }, self::MSG_GUARDAR);
    }

    /**
     * Cancela un cobro (nunca se borra). No se cancela uno con pagos vigentes (409): la plata
     * ya entro, hay que anular el pago primero. Cancelar dos veces es lo mismo que una.
     *
     * @return array ['success', ['cobro' => ..., 'repetido' => bool]] | ['error', ...]
     */
    public function cancelar(int $id, ?string $motivo, ?int $userId): array
    {
        return $this->protegido(function () use ($id, $motivo, $userId): array {
            $c = $this->filaCobro($id, true);
            if ($c === null) {
                return self::err(CobroReglas::MSG_NO_EXISTE, 404);
            }
            if ($c['cancelado_at'] !== null) {
                return ['success', ['cobro' => $this->obtener($id), 'repetido' => true]];
            }
            if (CobroReglas::centavosDb($c['monto_pagado']) > 0) {
                return self::err(CobroReglas::MSG_TIENE_PAGOS, 409, 'TIENE_PAGOS');
            }
            $ahora = $this->ahora();
            $this->db->prepare('UPDATE cobros SET cancelado_at = :ahora, motivo_cancelacion = :motivo, updated_at = :ahora2 WHERE id = :id')
                ->execute([':ahora' => $ahora, ':motivo' => $motivo, ':ahora2' => $ahora, ':id' => $id]);
            return ['success', ['cobro' => $this->obtener($id), 'repetido' => false]];
        }, self::MSG_GUARDAR);
    }

    /**
     * Reabre un cobro cancelado (vuelve a estar activo, con el estado que le toque por sus
     * fechas). Reabrir uno que no esta cancelado es lo mismo que nada ('repetido' => true).
     */
    public function reabrir(int $id, ?int $userId): array
    {
        return $this->protegido(function () use ($id): array {
            $c = $this->filaCobro($id, true);
            if ($c === null) {
                return self::err(CobroReglas::MSG_NO_EXISTE, 404);
            }
            if ($c['cancelado_at'] === null) {
                return ['success', ['cobro' => $this->obtener($id), 'repetido' => true]];
            }
            $this->db->prepare('UPDATE cobros SET cancelado_at = NULL, motivo_cancelacion = NULL, updated_at = :ahora WHERE id = :id')
                ->execute([':ahora' => $this->ahora(), ':id' => $id]);
            return ['success', ['cobro' => $this->obtener($id), 'repetido' => false]];
        }, self::MSG_GUARDAR);
    }

    /**
     * Anota un recordatorio que alguien hizo a mano. Solo un registro: la app no envia nada.
     * Un cobro cancelado o ya pagado no necesita recordatorio (409).
     *
     * @param array{fecha_hora:string, canal:string, nota:?string} $r de CobroReglas::validarRecordatorio
     * @return array ['success', ['recordatorio' => ..., 'cobro' => ...]] | ['error', ...]
     */
    public function registrarRecordatorio(int $cobroId, array $r, ?int $userId, ?string $userName): array
    {
        return $this->protegido(function () use ($cobroId, $r, $userId, $userName): array {
            $c = $this->filaCobro($cobroId, true);
            if ($c === null) {
                return self::err(CobroReglas::MSG_NO_EXISTE, 404);
            }
            if ($c['cancelado_at'] !== null) {
                return self::err(CobroReglas::MSG_CANCELADO, 409, 'COBRO_CANCELADO');
            }
            if (CobroReglas::centavosDb($c['monto']) - CobroReglas::centavosDb($c['monto_pagado']) <= 0) {
                return self::err('Este cobro ya está pagado: no hace falta recordarlo.', 409, 'YA_PAGADO');
            }
            if ($this->contarDe('cobro_recordatorios', $cobroId) >= CobroReglas::MAX_RECORDATORIOS) {
                return self::err(self::MSG_LIMITE_RECORDATORIOS, 409, 'LIMITE_RECORDATORIOS');
            }
            $this->db->prepare(
                'INSERT INTO cobro_recordatorios (cobro_id, fecha_hora, canal, nota, user_id, user_name, created_at)
                 VALUES (:cobro, :cuando, :canal, :nota, :user, :nombre, :ahora)'
            )->execute([
                ':cobro' => $cobroId, ':cuando' => $r['fecha_hora'], ':canal' => $r['canal'], ':nota' => $r['nota'],
                ':user' => $userId, ':nombre' => $userName !== null ? substr($userName, 0, 100) : null, ':ahora' => $this->ahora(),
            ]);
            $id = (int) $this->db->lastInsertId();
            $lista = $this->recordatoriosDe([$cobroId])[$cobroId] ?? [];
            $recordatorio = null;
            foreach ($lista as $x) {
                if ($x['id'] === $id) {
                    $recordatorio = $x;
                }
            }
            return ['success', ['recordatorio' => $recordatorio, 'cobro' => $this->obtener($cobroId)]];
        }, self::MSG_GUARDAR);
    }

    // ========================================================================
    // Piezas internas
    // ========================================================================

    /**
     * Genera el cobro del mes siguiente de un cobro que quedo pagado completo. Solo si el
     * cliente tiene configuracion ACTIVA; el monto es el mensual de AHORA, el vencimiento
     * sale del dia configurado. Si ese periodo ya tiene cobro (creado a mano, o por una
     * peticion anterior) no hace nada: es lo que vuelve idempotente repetir un pago.
     * Va DENTRO de la transaccion de quien llama.
     *
     * Dice tambien POR QUE no se genero, para que la pantalla no deje a la persona creyendo que la
     * cadena sigue: sin_config (el cliente no tiene cobro mensual), inactivo (lo tiene apagado) o
     * ya_existia (ese periodo ya tenia cobro).
     *
     * @param array|null $cobro fila de cobros ya pagado completo
     * @return array{cobro: ?array, motivo: string} cobro = el nuevo (misma forma que cobroJson) o null;
     *         motivo = generado | sin_config | inactivo | ya_existia
     */
    private function generarSiguiente(?array $cobro, ?int $userId): array
    {
        if ($cobro === null) {
            return ['cobro' => null, 'motivo' => 'sin_config'];
        }
        $clientId = (int) $cobro['client_id'];
        $config = $this->configInterna($clientId);
        if ($config === null) {
            return ['cobro' => null, 'motivo' => 'sin_config'];
        }
        if (!$config['activo']) {
            return ['cobro' => null, 'motivo' => 'inactivo'];
        }
        $periodo = CobroReglas::periodoSiguiente((string) $cobro['periodo']);
        $nuevo = $this->insertarCobro(
            $clientId, $periodo, $config['descripcion'], $config['monto_c'],
            CobroReglas::vencimientoDe($periodo, $config['dia']), (int) $cobro['id'], $userId
        );
        if ($nuevo === null) {
            return ['cobro' => null, 'motivo' => 'ya_existia'];
        }
        $f = $this->filaCobroCompleta($nuevo);
        return ['cobro' => $f !== null ? $this->cobroJson($f, $this->hoy()) : null, 'motivo' => 'generado'];
    }

    /**
     * La repeticion de un pago (misma clave de idempotencia). Solo es una repeticion si es del mismo
     * cobro, trae los mismos datos y el pago sigue vigente; en cualquier otro caso, un 409 que lo dice.
     */
    private function repeticion(array $previo, int $cobroId, array $p): array
    {
        if ((int) $previo['cobro_id'] !== $cobroId) {
            return self::err(self::MSG_CLAVE_USADA, 409, 'CLAVE_REPETIDA');
        }
        $igual = CobroReglas::centavosDb($previo['monto']) === $p['monto_c']
            && (string) $previo['fecha_pago'] === $p['fecha_pago']
            && (string) $previo['metodo'] === $p['metodo']
            && (string) ($previo['observaciones'] ?? '') === (string) ($p['observaciones'] ?? '');
        if (!$igual) {
            return self::err(self::MSG_CLAVE_OTROS_DATOS, 409, 'CLAVE_CON_OTROS_DATOS');
        }
        if ($previo['anulado_at'] !== null) {
            return self::err(self::MSG_CLAVE_ANULADA, 409, 'PAGO_ANULADO');
        }
        return ['success', $this->resultadoPago($previo, $cobroId, true)];
    }

    /** Cuantas filas de $tabla (cobro_pagos o cobro_recordatorios) tiene un cobro, anuladas incluidas. */
    private function contarDe(string $tabla, int $cobroId): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM ' . ($tabla === 'cobro_pagos' ? 'cobro_pagos' : 'cobro_recordatorios') . ' WHERE cobro_id = :id');
        $s->execute([':id' => $cobroId]);
        return (int) $s->fetchColumn();
    }

    /**
     * INSERT de un cobro. null si el cliente ya tiene cobro en ese periodo (la UNIQUE): quien
     * llama decide si eso es un 409 (crear a mano) o "ya estaba" (generar).
     */
    private function insertarCobro(int $clientId, string $periodo, ?string $descripcion, int $montoC, string $fecha, ?int $origen, ?int $userId): ?int
    {
        $s = $this->db->prepare('SELECT id FROM cobros WHERE client_id = :cid AND periodo = :periodo');
        $s->execute([':cid' => $clientId, ':periodo' => $periodo]);
        if ($s->fetchColumn() !== false) {
            return null;
        }
        try {
            $this->db->prepare(
                'INSERT INTO cobros (client_id, periodo, descripcion, monto, monto_pagado, fecha_vencimiento, generado_de_cobro_id, user_id, created_at)
                 VALUES (:cid, :periodo, :desc, :monto, 0, :fecha, :origen, :user, :ahora)'
            )->execute([
                ':cid' => $clientId, ':periodo' => $periodo, ':desc' => $descripcion, ':monto' => CobroReglas::aDecimal($montoC),
                ':fecha' => $fecha, ':origen' => $origen, ':user' => $userId, ':ahora' => $this->ahora(),
            ]);
        } catch (PDOException $e) {
            // Otra peticion lo creo entre la revision y el INSERT: la UNIQUE lo ataja.
            if (self::esDuplicado($e, ['uk_cobros_cliente_periodo', 'cobros.periodo'])) {
                return null;
            }
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    /** El 409 de "ya hay un cobro de este cliente en ese periodo", con el cobro que ya existe. */
    private function duplicado(int $clientId, string $periodo): array
    {
        // LOCK IN SHARE MODE: una lectura "actual". Con la plana, el snapshot de esta transaccion (REPEATABLE
        // READ, nacido antes de que otra peticion confirmara ese mismo periodo) no vería la fila y la
        // respuesta saldria sin el id del cobro que ya existe.
        $s = $this->db->prepare('SELECT id, cancelado_at, monto, monto_pagado, fecha_vencimiento FROM cobros WHERE client_id = :cid AND periodo = :periodo' . ($this->mysql ? ' LOCK IN SHARE MODE' : ''));
        $s->execute([':cid' => $clientId, ':periodo' => $periodo]);
        $f = $s->fetch();
        $estado = $f ? CobroReglas::estado($f['cancelado_at'] !== null, CobroReglas::centavosDb($f['monto']),
            CobroReglas::centavosDb($f['monto_pagado']), (string) $f['fecha_vencimiento'], $this->hoy()) : null;
        $texto = 'Este cliente ya tiene un cobro del periodo ' . $periodo . ($estado === CobroReglas::CANCELADO
            ? ', pero está cancelado: reábrelo en vez de crear otro.'
            : '. Ábrelo desde la lista para modificarlo o registrarle pagos.');
        return self::err($texto, 409, 'COBRO_DUPLICADO', ['cobro_id' => $f ? (int) $f['id'] : null, 'estado' => $estado, 'periodo' => $periodo]);
    }

    /**
     * Recalcula cobros.monto_pagado DESDE los pagos vigentes (no suma ni resta: asi un valor
     * desviado se corrige solo). Dentro de la transaccion y con el cobro ya bloqueado.
     * @return int el monto pagado, en centavos
     */
    private function recalcular(int $cobroId): int
    {
        $s = $this->db->prepare('SELECT monto FROM cobro_pagos WHERE cobro_id = :id AND anulado_at IS NULL' . $this->paraActualizar());
        $s->execute([':id' => $cobroId]);
        $suma = 0;
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $suma += CobroReglas::centavosDb($m);
        }
        $this->db->prepare('UPDATE cobros SET monto_pagado = :pagado, updated_at = :ahora WHERE id = :id')
            ->execute([':pagado' => CobroReglas::aDecimal($suma), ':ahora' => $this->ahora(), ':id' => $cobroId]);
        return $suma;
    }

    /** El resultado de registrarPago (tambien para la repeticion): el pago, el cobro y el cobro que genero. */
    private function resultadoPago(array $pago, int $cobroId, bool $repetido): array
    {
        $s = $this->db->prepare('SELECT id FROM cobros WHERE generado_de_cobro_id = :id ORDER BY id LIMIT 1');
        $s->execute([':id' => $cobroId]);
        $genero = $s->fetchColumn();
        $siguiente = null;
        if ($genero !== false) {
            $f = $this->filaCobroCompleta((int) $genero);
            $siguiente = $f !== null ? $this->cobroJson($f, $this->hoy()) : null;
        }
        return [
            'pago' => self::pagoJson($pago),
            'cobro' => $this->obtener($cobroId),
            'siguiente' => $siguiente,
            'siguiente_nuevo' => false,
            'siguiente_motivo' => null,
            'repetido' => $repetido,
        ];
    }

    /** El periodo que se sugiere para un cobro nuevo a mano (ver sugerido()). */
    private function periodoSugerido(int $clientId, string $hoy): string
    {
        $s = $this->db->prepare('SELECT MAX(periodo) FROM cobros WHERE client_id = :cid');
        $s->execute([':cid' => $clientId]);
        $ultimo = $s->fetchColumn();
        $actual = CobroReglas::periodoDe($hoy);
        if ($ultimo === false || $ultimo === null) {
            return $actual;
        }
        $siguiente = CobroReglas::periodoSiguiente((string) $ultimo);
        return max($siguiente, $actual);
    }

    /** Fila de cobros (sin cliente). $bloquear = SELECT ... FOR UPDATE (en MySQL). */
    private function filaCobro(int $id, bool $bloquear): ?array
    {
        $s = $this->db->prepare('SELECT * FROM cobros WHERE id = :id' . ($bloquear ? $this->paraActualizar() : ''));
        $s->execute([':id' => $id]);
        $f = $s->fetch();
        return $f ?: null;
    }

    /** Fila de cobros con sus datos de cliente y los contadores de la lista. */
    private function filaCobroCompleta(int $id): ?array
    {
        $s = $this->db->prepare(
            'SELECT c.*, ' . self::CLIENTE . ',
                    (SELECT MAX(p.fecha_pago) FROM cobro_pagos p WHERE p.cobro_id = c.id AND p.anulado_at IS NULL) AS ultimo_pago,
                    (SELECT MAX(r.fecha_hora) FROM cobro_recordatorios r WHERE r.cobro_id = c.id) AS ultimo_recordatorio,
                    (SELECT COUNT(*) FROM cobro_recordatorios r WHERE r.cobro_id = c.id) AS recordatorios
               FROM cobros c JOIN ' . $this->origen . ' cl ON cl.id = c.client_id WHERE c.id = :id'
        );
        $s->execute([':id' => $id]);
        $f = $s->fetch();
        return $f ?: null;
    }

    private function filaConfig(int $clientId, bool $bloquear): ?array
    {
        $s = $this->db->prepare('SELECT * FROM cobro_config WHERE client_id = :cid' . ($bloquear ? $this->paraActualizar() : ''));
        $s->execute([':cid' => $clientId]);
        $f = $s->fetch();
        return $f ?: null;
    }

    /** La configuracion en la forma que usa CobroReglas::resolverNuevo: monto_c, dia, descripcion, activo. */
    private function configInterna(int $clientId): ?array
    {
        $f = $this->filaConfig($clientId, false);
        return $f === null ? null : [
            'monto_c' => CobroReglas::centavosDb($f['monto_mensual']),
            'dia' => (int) $f['dia_vencimiento'],
            'descripcion' => $f['descripcion'] !== null ? (string) $f['descripcion'] : null,
            'activo' => (int) $f['activo'] === 1,
        ];
    }

    private function filaPago(int $id, bool $bloquear = false): ?array
    {
        $s = $this->db->prepare('SELECT * FROM cobro_pagos WHERE id = :id' . ($bloquear ? $this->paraActualizar() : ''));
        $s->execute([':id' => $id]);
        $f = $s->fetch();
        return $f ?: null;
    }

    private function pagoPorClave(string $clave): ?array
    {
        $s = $this->db->prepare('SELECT * FROM cobro_pagos WHERE idempotency_key = :clave');
        $s->execute([':clave' => $clave]);
        $f = $s->fetch();
        return $f ?: null;
    }

    /**
     * Los pagos de varios cobros, del mas nuevo al mas viejo: [cobro_id => list<pagoJson>].
     * @param list<int> $ids
     */
    private function pagosDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT * FROM cobro_pagos WHERE cobro_id IN ({$marcas}) ORDER BY fecha_pago DESC, id DESC");
        $s->execute(array_values($ids));
        $por = [];
        foreach ($s->fetchAll() as $f) {
            $por[(int) $f['cobro_id']][] = self::pagoJson($f);
        }
        return $por;
    }

    /**
     * Los recordatorios de varios cobros, del mas nuevo al mas viejo: [cobro_id => list<recordatorioJson>].
     * @param list<int> $ids
     */
    private function recordatoriosDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare(
            "SELECT r.*, c.periodo FROM cobro_recordatorios r JOIN cobros c ON c.id = r.cobro_id
              WHERE r.cobro_id IN ({$marcas}) ORDER BY r.fecha_hora DESC, r.id DESC"
        );
        $s->execute(array_values($ids));
        $por = [];
        foreach ($s->fetchAll() as $f) {
            $por[(int) $f['cobro_id']][] = self::recordatorioJson($f);
        }
        return $por;
    }

    /**
     * El ultimo recordatorio de cada cliente y cuantos tiene, en CUALQUIERA de sus cobros (pagados y
     * cancelados incluidos): [client_id => ['ultimo' => recordatorioJson, 'total' => int]]. Un cliente
     * sin recordatorios no aparece. Dos consultas acotadas por el numero de clientes (no por el de
     * recordatorios): el ultimo con una subconsulta correlacionada, el total con un GROUP BY.
     *
     * @param list<int> $clientIds
     */
    private function recordatoriosPorCliente(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($clientIds), '?'));
        $s = $this->db->prepare(
            "SELECT c.client_id, COUNT(*) AS total FROM cobro_recordatorios r JOIN cobros c ON c.id = r.cobro_id
              WHERE c.client_id IN ({$marcas}) GROUP BY c.client_id"
        );
        $s->execute(array_values($clientIds));
        $totales = [];
        foreach ($s->fetchAll() as $f) {
            $totales[(int) $f['client_id']] = (int) $f['total'];
        }
        if ($totales === []) {
            return [];
        }
        $s = $this->db->prepare(
            "SELECT r.*, c.periodo, c.client_id
               FROM cobro_recordatorios r JOIN cobros c ON c.id = r.cobro_id
              WHERE c.client_id IN ({$marcas})
                AND r.id = (SELECT r2.id FROM cobro_recordatorios r2 JOIN cobros c2 ON c2.id = r2.cobro_id
                             WHERE c2.client_id = c.client_id ORDER BY r2.fecha_hora DESC, r2.id DESC LIMIT 1)"
        );
        $s->execute(array_values($clientIds));
        $por = [];
        foreach ($s->fetchAll() as $f) {
            $cid = (int) $f['client_id'];
            $por[$cid] = ['ultimo' => self::recordatorioJson($f), 'total' => $totales[$cid] ?? 1];
        }
        return $por;
    }

    /** Un recordatorio como lo ve la API ($f = fila de cobro_recordatorios con el periodo de su cobro). */
    private static function recordatorioJson(array $f): array
    {
        return [
            'id' => (int) $f['id'], 'cobro_id' => (int) $f['cobro_id'], 'periodo' => (string) $f['periodo'],
            'fecha_hora' => (string) $f['fecha_hora'], 'canal' => (string) $f['canal'],
            'nota' => $f['nota'] !== null ? (string) $f['nota'] : null,
            'usuario' => $f['user_name'] !== null ? (string) $f['user_name'] : null,
            'created_at' => (string) $f['created_at'],
        ];
    }

    /**
     * El WHERE del listado. Los valores van como parametros (nunca pegados al SQL); el
     * estado se arma con las mismas reglas que CobroReglas::estado. $soloAbiertos agrega
     * "con saldo por cobrar" (para los proximos vencimientos del dashboard).
     *
     * @param array<string,mixed> $params se llena con los parametros nombrados
     */
    private function filtrar(array $f, string $hoy, array &$params, bool $soloAbiertos = false): string
    {
        $add = static function (mixed $v) use (&$params): string {
            $k = ':f' . count($params);
            $params[$k] = $v;
            return $k;
        };
        $w = ['1 = 1'];
        if ($soloAbiertos) {
            $w[] = self::ABIERTO;
        }
        if (!empty($f['client_id'])) {
            $w[] = 'c.client_id = ' . $add((int) $f['client_id']);
        }
        if (!empty($f['periodo'])) {
            $w[] = 'c.periodo = ' . $add($f['periodo']);
        }
        if (!empty($f['desde'])) {
            $w[] = 'c.fecha_vencimiento >= ' . $add($f['desde']);
        }
        if (!empty($f['hasta'])) {
            $w[] = 'c.fecha_vencimiento <= ' . $add($f['hasta']);
        }
        if (!empty($f['query'])) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $f['query']) . '%';
            $cols = ['cl.client_name', 'cl.company_name', 'cl.razon_social', 'cl.rnc'];
            $w[] = '(' . implode(' OR ', array_map(static fn(string $c): string => $c . ' LIKE ' . $add($like) . " ESCAPE '!'", $cols)) . ')';
        }
        $saldo = self::SALDO;
        switch ($f['estado'] ?? null) {
            case CobroReglas::CANCELADO:
                $w[] = 'c.cancelado_at IS NOT NULL';
                break;
            case CobroReglas::PAGADO:
                $w[] = "c.cancelado_at IS NULL AND {$saldo} <= 0";
                break;
            case CobroReglas::VENCIDO:
                $w[] = "c.cancelado_at IS NULL AND {$saldo} > 0 AND c.fecha_vencimiento < " . $add($hoy);
                break;
            case CobroReglas::PARCIAL:
                // Con abonos y todavia debe, VENZA O NO: un cobro vencido con abonos sigue siendo "Vencido" en
                // su etiqueta, pero tambien es uno parcial, y quien cobra quiere poder listar los que abonaron.
                $w[] = "c.cancelado_at IS NULL AND {$saldo} > 0 AND c.monto_pagado > 0";
                break;
            case CobroReglas::PENDIENTE:
                $w[] = "c.cancelado_at IS NULL AND {$saldo} > 0 AND c.fecha_vencimiento >= " . $add($hoy) . ' AND c.monto_pagado <= 0';
                break;
        }
        return implode(' AND ', $w);
    }

    /** ORDER BY del listado ($orden ya validado por CobroReglas::validarFiltros). */
    private static function orden(string $orden): string
    {
        $a = self::ABIERTO;
        return match ($orden) {
            'vencimiento_asc' => 'c.fecha_vencimiento ASC, c.id ASC',
            'vencimiento_desc' => 'c.fecha_vencimiento DESC, c.id DESC',
            'saldo_desc' => self::SALDO . ' DESC, c.fecha_vencimiento ASC, c.id ASC',
            // Lo que se debe primero (el mas atrasado arriba); despues lo ya cerrado, lo mas reciente primero.
            default => "CASE WHEN {$a} THEN 0 ELSE 1 END ASC, CASE WHEN {$a} THEN c.fecha_vencimiento END ASC, c.fecha_vencimiento DESC, c.id DESC",
        };
    }

    /** ' FOR UPDATE' en MySQL; SQLite (las pruebas) no lo conoce ni lo necesita. */
    private function paraActualizar(): string
    {
        return $this->mysql ? ' FOR UPDATE' : '';
    }

    /**
     * Corre $fn en una transaccion y la cierra segun lo que devuelva: ['success', ...] la
     * confirma, cualquier otra cosa (un ['error', ...]) la deshace, que tambien suelta los
     * bloqueos. Un PDOException deshace, va al error_log y sale como un 500 con texto neutro.
     * Si ya hay una transaccion abierta (quien llama la abrio) no abre otra.
     *
     * @param callable():array $fn
     */
    private function protegido(callable $fn, string $generico): array
    {
        $propia = !$this->db->inTransaction();
        try {
            if ($propia) {
                $this->db->beginTransaction();
            }
            $r = $fn();
            if ($propia && $this->db->inTransaction()) {
                $r[0] === 'success' ? $this->db->commit() : $this->db->rollBack();
            }
            return $r;
        } catch (PDOException $e) {
            if ($propia && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[cobros] ' . $e->getMessage());
            return self::err($generico, 500);
        }
    }

    /** ¿El PDOException es una clave unica repetida? Si se pasan $claves, que sea una de esas (MySQL nombra el indice; SQLite, las columnas). */
    private static function esDuplicado(PDOException $e, array $claves = []): bool
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        $texto = (string) ($e->errorInfo[2] ?? $e->getMessage());
        if ($codigo !== 1062 && stripos($texto, 'UNIQUE constraint failed') === false) {
            return false;
        }
        foreach ($claves as $k) {
            if (stripos($texto, $k) !== false) {
                return true;
            }
        }
        return $claves === [];
    }

    private static function err(string $texto, int $http, ?string $codigo = null, ?array $data = null): array
    {
        return ['error', $texto, $http, $codigo, $data];
    }

    // ------------------------------------------------------------------------
    // Forma de la respuesta
    // ------------------------------------------------------------------------

    /** Los datos del cliente, de las columnas cl_* de una fila. */
    private static function clienteJson(array $f): array
    {
        $contacto = (string) ($f['cl_contacto'] ?? '');
        $empresa = (string) ($f['cl_empresa'] ?? '');
        $razon = (string) ($f['cl_razon'] ?? '');
        $nombre = $razon !== '' ? $razon : ($empresa !== '' ? $empresa : ($contacto !== '' ? $contacto : '—'));
        return [
            'id' => (int) ($f['cl_id'] ?? 0), 'nombre' => $nombre, 'contacto' => $contacto, 'empresa' => $empresa,
            'rnc' => (string) ($f['cl_rnc'] ?? ''), 'email' => (string) ($f['cl_email'] ?? ''), 'telefono' => (string) ($f['cl_tel'] ?? ''),
        ];
    }

    /** La configuracion mensual como la ve la API. */
    private static function configJson(array $f): array
    {
        return [
            'client_id' => (int) ($f['client_id'] ?? 0),
            'monto_mensual' => CobroReglas::aNumero(CobroReglas::centavosDb($f['monto_mensual'] ?? 0)),
            'dia_vencimiento' => (int) ($f['dia_vencimiento'] ?? 0),
            'descripcion' => isset($f['descripcion']) ? (string) $f['descripcion'] : null,
            'activo' => (int) ($f['activo'] ?? 0) === 1,
            'updated_at' => isset($f['updated_at']) ? (string) $f['updated_at'] : (isset($f['created_at']) ? (string) $f['created_at'] : null),
        ];
    }

    /** Un pago como lo ve la API (sin la clave de idempotencia, que es interna). */
    private static function pagoJson(array $f): array
    {
        return [
            'id' => (int) ($f['id'] ?? 0), 'cobro_id' => (int) ($f['cobro_id'] ?? 0),
            'monto' => CobroReglas::aNumero(CobroReglas::centavosDb($f['monto'] ?? 0)),
            'fecha_pago' => (string) ($f['fecha_pago'] ?? ''), 'metodo' => (string) ($f['metodo'] ?? ''),
            'observaciones' => isset($f['observaciones']) ? (string) $f['observaciones'] : null,
            'anulado' => ($f['anulado_at'] ?? null) !== null,
            'anulado_at' => isset($f['anulado_at']) ? (string) $f['anulado_at'] : null,
            'motivo_anulacion' => isset($f['motivo_anulacion']) ? (string) $f['motivo_anulacion'] : null,
            'created_at' => (string) ($f['created_at'] ?? ''),
        ];
    }

    /**
     * Un cobro como lo ve la API, con su estado derivado contra $hoy.
     * saldo = lo que falta por cobrar (0 si esta cancelado). dias_atraso solo en un cobro
     * vencido; dias_para_vencer en uno abierto que aun no vencio (0 = vence hoy).
     */
    private function cobroJson(array $f, string $hoy): array
    {
        $montoC = CobroReglas::centavosDb($f['monto']);
        $pagadoC = CobroReglas::centavosDb($f['monto_pagado']);
        $cancelado = $f['cancelado_at'] !== null;
        $venc = (string) $f['fecha_vencimiento'];
        $estado = CobroReglas::estado($cancelado, $montoC, $pagadoC, $venc, $hoy);
        $saldoC = $cancelado ? 0 : max(0, $montoC - $pagadoC);
        $dias = CobroReglas::diasEntre($hoy, $venc);
        $abierto = !$cancelado && $saldoC > 0;
        return [
            'id' => (int) $f['id'],
            'client_id' => (int) $f['client_id'],
            'cliente' => self::clienteJson($f),
            'periodo' => (string) $f['periodo'],
            'descripcion' => $f['descripcion'] !== null ? (string) $f['descripcion'] : null,
            'monto' => CobroReglas::aNumero($montoC),
            'monto_pagado' => CobroReglas::aNumero($pagadoC),
            'saldo' => CobroReglas::aNumero($saldoC),
            'fecha_vencimiento' => $venc,
            'estado' => $estado,
            'dias_atraso' => $estado === CobroReglas::VENCIDO ? -$dias : 0,
            'dias_para_vencer' => $abierto && $dias >= 0 ? $dias : null,
            // Con pagos vigentes Y todavia debe algo (uno ya pagado completo no es "con abonos").
            'con_abonos' => !$cancelado && $pagadoC > 0 && $saldoC > 0,
            'cancelado_at' => $cancelado ? (string) $f['cancelado_at'] : null,
            'motivo_cancelacion' => isset($f['motivo_cancelacion']) ? (string) $f['motivo_cancelacion'] : null,
            'generado_de_cobro_id' => isset($f['generado_de_cobro_id']) ? (int) $f['generado_de_cobro_id'] : null,
            'ultimo_pago' => isset($f['ultimo_pago']) ? (string) $f['ultimo_pago'] : null,
            'ultimo_recordatorio' => isset($f['ultimo_recordatorio']) ? (string) $f['ultimo_recordatorio'] : null,
            'recordatorios' => (int) ($f['recordatorios'] ?? 0),
            'created_at' => (string) $f['created_at'],
            'updated_at' => isset($f['updated_at']) ? (string) $f['updated_at'] : null,
        ];
    }
}
