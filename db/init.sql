-- =====================================================================
--  Esquema inicial del target vulnerable.
--  La tabla 'users' contiene la credencial que el atacante exfiltra
--  via UNION-based SQL injection en product.php?id=
-- =====================================================================

USE appdb;

CREATE TABLE IF NOT EXISTS products (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    name        VARCHAR(120),
    description TEXT,
    price       DECIMAL(10,2)
);

INSERT INTO products (name, description, price) VALUES
  ('Taladro percutor 750W', 'Ideal para mamposteria',      59.90),
  ('Set destornilladores',  'Kit de 32 piezas',            19.50),
  ('Manguera 20m',          'Reforzada anti-torsion',      24.00),
  ('Bombilla LED E27',      '9W luz calida, pack x4',       8.75);

CREATE TABLE IF NOT EXISTS users (
    id       INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(60),
    password VARCHAR(120),
    role     VARCHAR(20)
);

-- El 'flag' del laboratorio: credencial sensible del backend.
INSERT INTO users (username, password, role) VALUES
  ('admin',    'S3cr3t_FlaG_db_2026',  'superadmin'),
  ('jsmith',   'Summer2025!',          'editor'),
  ('soporte',  'helpdesk-temp',        'support');

-- =====================================================================
--  "JOYA DE LA CORONA": datos personales de clientes (PII).
--  TODOS LOS DATOS SON FICTICIOS (tarjetas de test, DNIs inventados).
--  Es el objetivo realista de una exfiltracion de base de datos.
-- =====================================================================
CREATE TABLE IF NOT EXISTS customers (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    full_name   VARCHAR(120),
    email       VARCHAR(120),
    phone       VARCHAR(30),
    credit_card VARCHAR(25),
    national_id VARCHAR(20),
    address     VARCHAR(200)
);

INSERT INTO customers (full_name, email, phone, credit_card, national_id, address) VALUES
  ('Lucia Fernandez',  'lucia.fernandez@example.com', '+34 600 111 222', '4111 1111 1111 1111', 'X1234567A', 'Calle Mayor 12, Madrid'),
  ('Marco Rossi',      'marco.rossi@example.com',     '+39 333 444 5566', '5500 0000 0000 0004', 'Y7654321B', 'Via Roma 8, Milano'),
  ('Sophie Dubois',    'sophie.dubois@example.com',   '+33 6 12 34 56 78', '3400 0000 0000 009',  'Z1122334C', '14 Rue de la Paix, Paris'),
  ('John Carter',      'john.carter@example.com',     '+1 415 555 0142',  '6011 0000 0000 0004', 'SSN-555-12-3456', '742 Evergreen Terrace, CA'),
  ('Ana Souza',        'ana.souza@example.com',       '+55 11 98888 7777','3530 1113 3330 0000', 'CPF-123.456.789-00','Av Paulista 1000, Sao Paulo'),
  ('Kenji Tanaka',     'kenji.tanaka@example.com',    '+81 90 1234 5678', '4012 8888 8888 1881', 'JP-998877665',   'Shibuya 1-2-3, Tokyo'),
  ('Olga Petrova',     'olga.petrova@example.com',    '+7 916 555 1234',  '4222 2222 2222 2',    'RU-445566778',   'Tverskaya 7, Moscow');

-- Tabla de secretos de aplicacion (API keys, tokens) -> otro objetivo.
CREATE TABLE IF NOT EXISTS api_keys (
    id        INT PRIMARY KEY AUTO_INCREMENT,
    service   VARCHAR(60),
    api_key   VARCHAR(120),
    is_active TINYINT DEFAULT 1
);

INSERT INTO api_keys (service, api_key) VALUES
  ('stripe',   'sk_live_FAKE_51H8xQ 2eZvKYlo2C9aBcDeFg'),
  ('aws_s3',   'AKIAFAKE1234567890XQ'),
  ('sendgrid', 'SG.FAKE_aBcDeFgHiJkLmNoPqRsTuV.WxYz');
