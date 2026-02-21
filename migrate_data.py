import re
import datetime
import html

def parse_sql_values(line):
    # Basic SQL value parser for (1, 'val', 2, 'val')
    # This is a simplified version; a more robust one might be needed for escaped quotes
    values = []
    current = ""
    in_string = False
    escaped = False
    
    # Remove leading '(' and trailing '),' or ');'
    line = line.strip()
    if line.startswith('('): line = line[1:]
    if line.endswith('),'): line = line[:-2]
    if line.endswith(');'): line = line[:-2]
    
    i = 0
    while i < len(line):
        char = line[i]
        if char == "'" and not escaped:
            in_string = not in_string
        elif char == "\\" and in_string:
            escaped = not escaped
            current += char
        elif char == "," and not in_string:
            values.append(current.strip().strip("'"))
            current = ""
        else:
            escaped = False
            current += char
        i += 1
    values.append(current.strip().strip("'"))
    return values

def strip_original_data(sql_content):
    lines = sql_content.split('\n')
    output = []
    skipping = False
    for line in lines:
        trimmed = line.strip()
        # Skip INSERT INTO and DELETE FROM blocks
        if trimmed.upper().startswith('INSERT INTO') or trimmed.upper().startswith('DELETE FROM'):
            if not trimmed.endswith(';'):
                skipping = True
            continue
        if skipping:
            if trimmed.endswith(';'):
                skipping = False
            continue
        # Also skip phpMyAdmin metadata comments related to dumping data if present
        if trimmed.startswith('-- Dumping data for table'):
            continue
        output.append(line)
    return '\n'.join(output)

def fix_accents(text):
    if not text: return text
    
    # 1. Fix HTML entities
    text = html.unescape(text)
    
    # 2. Repair double-encoded UTF-8 sequences
    # We use a regex to find potential UTF-8 sequences (2 bytes starting with 0xCx)
    def repair_match(match):
        try:
            return match.group(0).encode('latin-1').decode('utf-8')
        except:
            return match.group(0)
            
    # Pattern for 2-byte UTF-8 sequences in latin-1 (Ã followed by another char)
    text = re.sub(r'[\xc2-\xc3][\x80-\xbf]', repair_match, text)
    
    # 3. Aggressive manual replacements for common mess patterns that escaped the above
    replacements = {
        'Ã¡': 'á', 'Ã©': 'é', 'Ã­': 'í', 'Ã³': 'ó', 'Ãº': 'ú', 'Ã±': 'ñ',
        'Ã‘': 'Ñ', 'Ã ': 'Á', 'Ã‰': 'É', 'Ã ': 'Í', 'Ã“': 'Ó', 'Ãš': 'Ú',
        'Â­': 'í', 'Â¡': 'á', 'Â©': 'é', 'Â³': 'ó', 'Âº': 'ú', 'Â±': 'ñ',
        'ÃƒÂ¡': 'á', 'ÃƒÂ©': 'é', 'ÃƒÂ­': 'í', 'ÃƒÂ³': 'ó', 'ÃƒÂº': 'ú', 'ÃƒÂ±': 'ñ',
        'iÂ­': 'í', 'i­': 'í', 'nÂ±': 'ñ', 'aÂ¡': 'á', 'oÂ³': 'ó', 'eÂ©': 'é', 'uÂº': 'ú',
        'Ã': 'í', # Often a trailing or isolated Ã is í in this data
        'Â': '',  # Strip remaining Â
    }
    
    for old, new in replacements.items():
        text = text.replace(old, new)
        
    return text

def migrate():
    source_file = 'db/legacy_database.sql'
    target_schema_file = 'db/database.sql'
    output_file = 'migrated_database_clean.sql'
    
    with open(target_schema_file, 'r', encoding='utf-8') as f:
        schema_content = f.read()
    
    # Strip initial data from schema
    clean_schema = strip_original_data(schema_content)
    output_sql = clean_schema + "\n\n-- MIGRATED LEGACY DATA --\n\n"

    with open(source_file, 'r', encoding='latin-1') as f:
        lines = f.readlines()

    # Mappings for linking
    codigo_to_client_id = {}
    name_to_client_id = {}
    company_to_client_id = {}
    
    # Data structures to hold transformed data
    migrated_users = []
    migrated_clients = []
    used_usernames = set()
    
    # Simple ID counters for new tables if needed
    factura_item_id_seq = 1
    cot_item_id_seq = 1

    # FIRST PASS: Extract all users and build mapping
    current_table = None
    for line in lines:
        if 'INSERT INTO `usuarios`' in line:
            current_table = 'usuarios'
            continue
        if line.strip().startswith('(') and current_table == 'usuarios':
            vals = parse_sql_values(line)
            try:
                # id, codigo, usuario, rol, nombre, apellido, email, psw, empresa, telefono, celular, rnc, tipo_empresa, newsletter, tracking
                user_id = vals[0]
                codigo = vals[1]
                base_username = vals[2].strip()
                role = vals[3] if vals[3] else 'user'
                name = fix_accents(vals[4]).strip()
                last_name = fix_accents(vals[5]).strip()
                email = vals[6]
                password = vals[7]
                company = fix_accents(vals[8]).strip()
                phone = vals[9] if vals[9] else vals[10]
                
                full_name = f"{name} {last_name}".strip()
                name_esc = name.replace("'", "''")
                last_name_esc = last_name.replace("'", "''")
                full_name_esc = full_name.replace("'", "''")
                company_esc = company.replace("'", "''")

                if role == 'admin':
                    if not base_username: base_username = "admin"
                    username = base_username
                    counter = 1
                    while username in used_usernames:
                        username = f"{base_username}{counter}"
                        counter += 1
                    used_usernames.add(username)
                    
                    migrated_users.append(f"({user_id}, '{name_esc}', '{last_name_esc}', '{email}', '{username}', '{password}', '{role}')")
                else:
                    # Move to clients table
                    migrated_clients.append(f"({user_id}, '{email}', '{full_name_esc}', '{company_esc}', '{phone}')")
                    
                    # Populate mappings for second pass
                    codigo_to_client_id[codigo] = user_id
                    if full_name:
                        name_to_client_id[full_name.lower()] = user_id
                    if company:
                        company_to_client_id[company.lower()] = user_id
            except IndexError: pass
        elif not line.strip().startswith('(') and current_table == 'usuarios':
            if line.strip().endswith(';'):
                current_table = None

    # SECOND PASS: Process other tables
    migrated_facturas = []
    migrated_factura_items = []
    migrated_cotizaciones = []
    migrated_cotizacion_items = []
    migrated_ncf = []
    
    current_table = None
    buffer = ""
    in_tuple = False
    
    for line in lines:
        stripped = line.strip()
        
        if 'INSERT INTO `cotizacion`' in stripped:
            current_table = 'cotizacion'
            continue
        if 'INSERT INTO `facturas`' in stripped:
            current_table = 'facturas'
            continue
        if 'INSERT INTO `Comprobante`' in stripped:
            current_table = 'Comprobante'
            continue
            
        if not current_table:
            continue
            
        if stripped.startswith('('):
            in_tuple = True
            buffer = line
        elif in_tuple:
            buffer += line
            
        if in_tuple and (stripped.endswith('),') or stripped.endswith(');')):
            in_tuple = False
            vals = parse_sql_values(buffer)
            buffer = ""
            
            if current_table == 'cotizacion':
                # id, nombre, no_orden, codigo_usuario, descripcion, cantidad, unitario, total, fecha
                try:
                    cot_id = vals[0]
                    client_name_legacy = fix_accents(vals[1]).replace("'", "''").replace('Cotizacion_#', '').replace('.pdf', '')
                    no_orden = vals[2]
                    codigo_usuario = vals[3]
                    desc = fix_accents(vals[4]).replace("'", "''")
                    qty = vals[5]
                    unit = vals[6]
                    total = vals[7]
                    date_val = vals[8]
                    
                    client_id = codigo_to_client_id.get(codigo_usuario, 'NULL')
                    client_name_esc = client_name_legacy.replace("'", "''")
                    
                    migrated_cotizaciones.append(f"({cot_id}, '{no_orden}', '{date_val}', {client_id}, '{client_name_esc}', {total})")
                    migrated_cotizacion_items.append(f"({cot_item_id_seq}, {cot_id}, '{desc}', {unit}, {qty}, {total})")
                    cot_item_id_seq += 1
                except IndexError: pass

            elif current_table == 'facturas':
                # id, descripcion, empresa, persona, fecha, no_factura, total, ncf
                try:
                    f_id = vals[0]
                    desc = fix_accents(vals[1]).replace("'", "''")
                    empresa = fix_accents(vals[2]).strip()
                    persona = fix_accents(vals[3]).strip()
                    # Convert date from DD/MM/YYYY to YYYY-MM-DD
                    date_parts = vals[4].split('/')
                    if len(date_parts) == 3:
                        formatted_date = f"{date_parts[2]}-{date_parts[1]}-{date_parts[0]} 00:00:00"
                    else:
                        formatted_date = vals[4]
                    
                    no_factura = vals[5]
                    total = vals[6] if vals[6] else '0'
                    ncf = vals[7]
                    
                    # Try to find client_id
                    client_id = 'NULL'
                    if empresa:
                        client_id = company_to_client_id.get(empresa.lower(), 'NULL')
                    if client_id == 'NULL' and persona:
                        client_id = name_to_client_id.get(persona.lower(), 'NULL')
                        
                    client_name_final_esc = (empresa if empresa else persona).replace("'", "''")
                    
                    migrated_facturas.append(f"({f_id}, '{no_factura}', '{formatted_date}', {client_id}, '{client_name_final_esc}', {total}, '{ncf}')")
                    migrated_factura_items.append(f"({factura_item_id_seq}, {f_id}, '{desc}', {total}, 1, {total})")
                    factura_item_id_seq += 1
                except IndexError: pass

            elif current_table == 'Comprobante':
                # id, nombre, prefijo, valor, descripcion
                try:
                    ncf_id = vals[0]
                    ncf_type = fix_accents(vals[1]).replace("'", "''")
                    ncf_prefix = vals[2]
                    ncf_val = vals[3] if vals[3] else '0'
                    ncf_desc = fix_accents(vals[4]).replace("'", "''")
                    migrated_ncf.append(f"({ncf_id}, '{ncf_type}', '{ncf_prefix}', {ncf_val}, '{ncf_desc}')")
                except IndexError: pass

            if stripped.endswith(';'):
                current_table = None

    # Construct final SQL
    if migrated_clients:
        output_sql += "INSERT INTO `clients` (`id`, `email`, `client_name`, `company_name`, `phone_number`) VALUES\n"
        output_sql += ",\n".join(migrated_clients) + ";\n\n"

    if migrated_users:
        output_sql += "INSERT INTO `users` (`id`, `name`, `last_name`, `email`, `username`, `password`, `role`) VALUES\n"
        output_sql += ",\n".join(migrated_users) + ";\n\n"
    
    if migrated_cotizaciones:
        output_sql += "INSERT INTO `cotizaciones` (`id`, `code`, `date`, `client_id`, `client_name`, `total`) VALUES\n"
        output_sql += ",\n".join(migrated_cotizaciones) + ";\n\n"
        
    if migrated_cotizacion_items:
        output_sql += "INSERT INTO `cotizacion_items` (`id`, `cotizacion_id`, `description`, `amount`, `quantity`, `subtotal`) VALUES\n"
        output_sql += ",\n".join(migrated_cotizacion_items) + ";\n\n"

    if migrated_facturas:
        output_sql += "INSERT INTO `facturas` (`id`, `no_factura`, `date`, `client_id`, `client_name`, `total`, `NCF`) VALUES\n"
        output_sql += ",\n".join(migrated_facturas) + ";\n\n"
    
    if migrated_factura_items:
        output_sql += "INSERT INTO `factura_items` (`id`, `factura_id`, `description`, `amount`, `quantity`, `subtotal`) VALUES\n"
        output_sql += ",\n".join(migrated_factura_items) + ";\n\n"

    if migrated_ncf:
        output_sql += "INSERT INTO `ncf_sequences` (`id`, `type`, `prefix`, `current_value`, `description`) VALUES\n"
        output_sql += ",\n".join(migrated_ncf) + ";\n\n"

    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(output_sql)
    
    print(f"Migration complete. Generated {output_file}")

if __name__ == '__main__':
    migrate()
