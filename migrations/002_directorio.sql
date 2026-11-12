-- Directorio de empresas. Una empresa del directorio y un profesional que recibe leads son lo mismo:
-- una fila de "profesionales" con ficha pública. Así todas siguen el mismo recorrido (alta, ficha, leads).

ALTER TABLE profesionales ADD COLUMN slug TEXT;                                  -- URL pública /empresa/{slug}/
ALTER TABLE profesionales ADD COLUMN municipio_ine TEXT NOT NULL DEFAULT '';     -- base: da las coordenadas
ALTER TABLE profesionales ADD COLUMN servicios TEXT NOT NULL DEFAULT '[]';       -- JSON con claves de config/servicios.php
ALTER TABLE profesionales ADD COLUMN descripcion TEXT NOT NULL DEFAULT '';
ALTER TABLE profesionales ADD COLUMN web TEXT NOT NULL DEFAULT '';
ALTER TABLE profesionales ADD COLUMN telefono TEXT NOT NULL DEFAULT '';
ALTER TABLE profesionales ADD COLUMN radio_km INTEGER NOT NULL DEFAULT 25;      -- hasta dónde trabaja
-- auto: la añadimos nosotros · empresa: alta propia · propia: tus marcas o tu profesional
ALTER TABLE profesionales ADD COLUMN origen TEXT NOT NULL DEFAULT 'propia';
-- pendiente: esperando revisión · publicada · oculta (sin ficha pública; puede seguir recibiendo leads)
ALTER TABLE profesionales ADD COLUMN ficha TEXT NOT NULL DEFAULT 'oculta';
ALTER TABLE profesionales ADD COLUMN destacada_hasta TEXT;                       -- AAAA-MM-DD; caduca sola
ALTER TABLE profesionales ADD COLUMN prioridad INTEGER NOT NULL DEFAULT 0;       -- ajuste interno del orden
ALTER TABLE profesionales ADD COLUMN verificada INTEGER NOT NULL DEFAULT 0;      -- la empresa confirmó su email
ALTER TABLE profesionales ADD COLUMN actualizado TEXT;

CREATE UNIQUE INDEX profesionales_slug ON profesionales (slug) WHERE slug IS NOT NULL;
CREATE INDEX profesionales_ficha ON profesionales (ficha);

-- Ajustes internos (p. ej. huella del último archivo de empresas importado).
CREATE TABLE ajustes (
    clave TEXT PRIMARY KEY,
    valor TEXT NOT NULL
);
