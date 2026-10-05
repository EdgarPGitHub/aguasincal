-- Esquema inicial (SQLite). Fechas en ISO 8601 (UTC).

CREATE TABLE profesionales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre_comercial TEXT NOT NULL,
    razon_social TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    whatsapp TEXT NOT NULL DEFAULT '',
    precio_lead REAL NOT NULL DEFAULT 0,
    notas TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL
);

-- Provincia (código INE) × servicio. Si no hay fila, el modo es 'espera'.
CREATE TABLE cobertura (
    provincia TEXT NOT NULL,
    servicio TEXT NOT NULL,
    modo TEXT NOT NULL CHECK (modo IN ('activo', 'espera')),
    profesional_id INTEGER REFERENCES profesionales(id),
    actualizado TEXT NOT NULL,
    PRIMARY KEY (provincia, servicio)
);

CREATE TABLE leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    creado TEXT NOT NULL,
    actualizado TEXT NOT NULL,
    origen TEXT NOT NULL DEFAULT 'web' CHECK (origen IN ('web', 'telefono', 'whatsapp')),
    servicio TEXT NOT NULL,
    modalidad TEXT NOT NULL DEFAULT '',
    tipo_cliente TEXT NOT NULL,
    cp TEXT NOT NULL,
    provincia TEXT NOT NULL,
    municipio TEXT NOT NULL DEFAULT '',
    personas INTEGER,
    mensaje TEXT NOT NULL DEFAULT '',
    nombre TEXT NOT NULL,
    telefono TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    modo TEXT NOT NULL CHECK (modo IN ('activo', 'espera')),
    profesional_id INTEGER REFERENCES profesionales(id),
    estado TEXT NOT NULL CHECK (estado IN ('nuevo', 'espera', 'enviado', 'valido', 'invalido')),
    motivo_invalido TEXT NOT NULL DEFAULT '',
    notas TEXT NOT NULL DEFAULT '',
    consentimiento_version TEXT NOT NULL DEFAULT '',
    pagina_origen TEXT NOT NULL DEFAULT '',
    referrer TEXT NOT NULL DEFAULT '',
    utm TEXT NOT NULL DEFAULT '',
    ip_hash TEXT NOT NULL DEFAULT '',
    enviado TEXT,
    anonimizado TEXT
);
CREATE INDEX leads_creado ON leads (creado);
CREATE INDEX leads_provincia_estado ON leads (provincia, estado);
CREATE INDEX leads_telefono ON leads (telefono);

-- Clics agregados por día (sin datos personales).
CREATE TABLE eventos (
    dia TEXT NOT NULL,
    pagina TEXT NOT NULL,
    tipo TEXT NOT NULL,
    total INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (dia, pagina, tipo)
);

CREATE TABLE admin_usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    totp_secreto TEXT NOT NULL DEFAULT '',
    totp_activo INTEGER NOT NULL DEFAULT 0,
    creado TEXT NOT NULL,
    ultimo_acceso TEXT
);

-- Límite de intentos (login, formularios). Se purga a diario.
CREATE TABLE intentos (
    clave TEXT NOT NULL,
    momento INTEGER NOT NULL
);
CREATE INDEX intentos_clave ON intentos (clave, momento);
