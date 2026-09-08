<?php
require_once(__DIR__ . '/../Database.php');

class clientModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getClients($id = null)
    {
        try {
            if ($id == null) {
                $sql = "SELECT * FROM clients";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute();
            } else {
                $sql = "SELECT * FROM clients WHERE id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Alta de un cliente.
     *
     * Recibe un arreglo en vez de una lista de parametros posicionales: la
     * lista se habia quedado corta y direccion/municipio/provincia se perdian
     * en silencio — el formulario los mandaba, el controller no los leia y
     * nadie se enteraba hasta abrir la ficha y ver el campo vacio. Con un
     * arreglo, agregar una columna es tocar CAMPOS_TEXTO o el SQL, no encajar
     * un argumento mas en el orden correcto de doce.
     *
     * @param array $d email, client_name, company_name, phone_number, rnc,
     *                 razon_social, direccion, municipio, provincia, descuento,
     *                 permitir_credito
     */
    public function saveClient(array $d)
    {
        try {
            $valida = $this->validateClients(
                $d['email'] ?? null,
                $d['client_name'] ?? null,
                $d['company_name'] ?? null,
                $d['phone_number'] ?? null
            );
            $resultado = ['error', 'This client already exists'];
            if (count($valida) == 0) {
                $sql = "INSERT INTO clients(email, client_name, company_name, phone_number, rnc,
                                            razon_social, direccion, municipio, provincia,
                                            descuento, permitir_credito)
                    VALUES(:email, :client_name, :company_name, :phone_number, :rnc,
                           :razon_social, :direccion, :municipio, :provincia,
                           :descuento, :permitir_credito)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':email' => $d['email'] ?? null,
                    ':client_name' => $d['client_name'] ?? null,
                    ':company_name' => $d['company_name'] ?? null,
                    ':phone_number' => $d['phone_number'] ?? null,
                    ':rnc' => $this->normalizeRnc($d['rnc'] ?? null),
                    // razon_social cae a company_name cuando no viene: los
                    // clientes de la API que solo mandan empresa siguen igual.
                    ':razon_social' => $this->normalizeTexto($d['razon_social'] ?? null)
                        ?? ($d['company_name'] ?? null),
                    ':direccion' => $this->normalizeTexto($d['direccion'] ?? null),
                    ':municipio' => $this->normalizeTexto($d['municipio'] ?? null),
                    ':provincia' => $this->normalizeTexto($d['provincia'] ?? null),
                    // Las columnas son NOT NULL: en alta, "no enviado" = 0.
                    ':descuento' => $this->normalizeDescuento($d['descuento'] ?? null) ?? 0.0,
                    ':permitir_credito' => $this->normalizeCredito($d['permitir_credito'] ?? null) ?? 0
                ]);
                $resultado = ['success', 'Client saved', (int) $this->conexion->lastInsertId()];
            }
            return $resultado;
        } catch (PDOException $e) {
            return ['error', 'Failed to save client'];
        }
    }

    /**
     * Actualizacion PARCIAL de un cliente. Igual que saveClient, recibe un
     * arreglo: solo las claves PRESENTES se escriben; las ausentes conservan su
     * valor (COALESCE). Asi se puede corregir un dato suelto —el RNC desde la
     * pantalla de factura, por ejemplo— sin reenviar el registro completo, que
     * ademas fallaria en los clientes migrados (sin correo ni telefono).
     *
     * "Presente con cadena vacia" SI escribe: es como se vacia una direccion.
     * Solo la ausencia de la clave conserva.
     *
     * @param array $d mismas claves que saveClient (sin id)
     */
    public function updateClient($id, array $d)
    {
        try {
            $existe = $this->getClients($id);
            $resultado = ['error', "There is no client with ID {$id}"];
            if (count($existe) > 0) {
                $valida = $this->validateClients(
                    $d['email'] ?? null,
                    $d['client_name'] ?? null,
                    $d['company_name'] ?? null,
                    $d['phone_number'] ?? null,
                    $id
                );
                $resultado = ['error', 'This client already exists'];
                if (count($valida) == 0) {
                    $sql = "UPDATE clients SET
                        email = COALESCE(:email, email),
                        client_name = COALESCE(:client_name, client_name),
                        company_name = COALESCE(:company_name, company_name),
                        phone_number = COALESCE(:phone_number, phone_number),
                        rnc = COALESCE(:rnc, rnc),
                        razon_social = COALESCE(:razon_social, razon_social),
                        direccion = COALESCE(:direccion, direccion),
                        municipio = COALESCE(:municipio, municipio),
                        provincia = COALESCE(:provincia, provincia),
                        descuento = COALESCE(:descuento, descuento),
                        permitir_credito = COALESCE(:permitir_credito, permitir_credito) WHERE id = :id";
                    $stmt = $this->conexion->prepare($sql);
                    $stmt->execute([
                        ':id' => $id,
                        ':email' => $d['email'] ?? null,
                        ':client_name' => $d['client_name'] ?? null,
                        ':company_name' => $d['company_name'] ?? null,
                        ':phone_number' => $d['phone_number'] ?? null,
                        ':rnc' => $this->normalizeRnc($d['rnc'] ?? null),
                        // razon_social propia si vino con contenido; si no, sigue
                        // a company_name (comportamiento historico para quien
                        // solo manda empresa). Antes SIEMPRE la pisaba
                        // company_name, asi que el campo "Razon social" del
                        // formulario no se podia guardar distinto del de
                        // "Empresa".
                        //
                        // Nunca se escribe "": es RazonSocialComprador en el
                        // e-CF y la DGII lo exige con contenido. Vacia +
                        // company_name -> company_name; vacia y sin empresa ->
                        // null y el COALESCE conserva la que ya tenia.
                        ':razon_social' => $this->normalizeTexto($d['razon_social'] ?? null)
                            ?? $this->normalizeTexto($d['company_name'] ?? null),
                        // Direccion y ubicacion: el formulario las manda y hasta
                        // ahora se descartaban en silencio.
                        ':direccion' => $d['direccion'] ?? null,
                        ':municipio' => $d['municipio'] ?? null,
                        ':provincia' => $d['provincia'] ?? null,
                        // null = el PUT no las mando: el COALESCE del SQL conserva
                        // lo que ya tenia. Asi un cliente de otro consumidor del
                        // API no pierde su descuento ni su credito al editarlo.
                        ':descuento' => $this->normalizeDescuento($d['descuento'] ?? null),
                        ':permitir_credito' => $this->normalizeCredito($d['permitir_credito'] ?? null)
                    ]);
                    $resultado = ['success', 'Client updated'];
                }
            }
            return $resultado;
        } catch (PDOException $e) {
            return ['error', 'Failed to update client'];
        }
    }

    public function deleteClient($id)
    {
        try {
            $valida = $this->getClients($id);
            $resultado = ['error', "Client not found {$id}"];
            if (count($valida) > 0) {
                $sql = "DELETE FROM clients WHERE id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
                $resultado = ['success', 'Client deleted'];
            }
            return $resultado;
        } catch (PDOException $e) {
            return ['error', 'Failed to delete client'];
        }
    }

    /**
     * Texto opcional para el ALTA: recorta y trata "" como null, que en un
     * INSERT son lo mismo (no habia nada antes).
     *
     * A proposito NO se usa en updateClient: ahi "" es un valor real —es como se
     * borra una direccion— y convertirlo a null haria que el COALESCE conservara
     * el valor viejo, dejando un campo imposible de vaciar.
     */
    private function normalizeTexto($valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpio = trim((string) $valor);
        return $limpio === '' ? null : $limpio;
    }

    private function normalizeRnc($rnc): ?string
    {
        if ($rnc === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $rnc);
        return $digits === '' ? null : $digits;
    }

    /**
     * Descuento del cliente en %. Las columnas son NOT NULL DEFAULT 0, asi que
     * null/vacio = 0 (sin descuento), no "conservar el anterior". Se acota a
     * 0-100 para que un dato sucio del catalogo anterior no entre como 999.
     */
    private function normalizeDescuento($valor): ?float
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }
        return max(0.0, min(100.0, round((float) $valor, 2)));
    }

    /**
     * permitir_credito: cualquier cosa distinta de 1 es 0 (solo contado).
     * null solo cuando no vino en el request, para poder distinguir "ponlo en 0"
     * de "no lo toques".
     */
    private function normalizeCredito($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        return ((int) $valor) === 1 ? 1 : 0;
    }

    // Duplicate check intentionally ignores rnc and company_name: multiple
    // clients may share the same RNC or company_name (e.g. several contacts of
    // the same company). Only an exact email + client_name + phone match counts
    // as a duplicate, to guard against accidental re-submission.
    /**
     * Busca un cliente ya existente con el mismo email + nombre + telefono.
     *
     * $excludeId: al EDITAR hay que excluir el propio registro. Sin eso el
     * cliente se encontraba a si mismo y toda edicion moria con "This client
     * already exists" — y peor con catalogos migrados, donde muchos comparten
     * email y telefono vacios.
     */
    public function validateClients($email, $client_name, $company_name, $phone_number, $excludeId = null)
    {
        try {
            $sql = "SELECT * FROM clients WHERE email = :email AND client_name = :client_name AND phone_number = :phone_number";
            $params = [
                ':email' => $email,
                ':client_name' => $client_name,
                ':phone_number' => $phone_number
            ];
            if ($excludeId !== null) {
                $sql .= ' AND id <> :exclude_id';
                $params[':exclude_id'] = $excludeId;
            }
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getClientsPaginated($offset, $limit, $query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (client_name LIKE :query OR company_name LIKE :query OR email LIKE :query OR phone_number LIKE :query OR rnc LIKE :query)";
            }
            $sql = "SELECT * FROM clients {$whereClause} ORDER BY id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->bindValue(':query', "%{$query}%", \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getClientsCount($query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (client_name LIKE :query OR company_name LIKE :query OR email LIKE :query OR phone_number LIKE :query OR rnc LIKE :query)";
            }
            $sql = "SELECT COUNT(*) as total FROM clients {$whereClause}";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->execute([':query' => "%{$query}%"]);
            } else {
                $stmt->execute();
            }
            $row = $stmt->fetch();
            return $row ? (int)$row['total'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }
}
