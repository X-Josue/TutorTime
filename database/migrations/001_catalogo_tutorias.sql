-- Migración inicial de catálogo y tutorías individuales para TutorTime
-- Compatible con MariaDB 10.4 / MySQL de XAMPP.
-- No elimina ni modifica las tablas actuales: materias, alumnos_materias,
-- chats, mensajes_chat, tareas y usuarios se conservan.
--
-- Antes de ejecutar:
-- 1. Haz una copia de seguridad de la base de datos tutortime.
-- 2. Selecciona la base de datos tutortime en phpMyAdmin.
-- 3. Ejecuta este archivo en la pestaña SQL.

CREATE TABLE IF NOT EXISTS categorias (
    id INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categorias_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_materias (
    id INT NOT NULL AUTO_INCREMENT,
    categoria_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogo_categoria_nombre (categoria_id, nombre),
    KEY idx_catalogo_categoria (categoria_id),
    CONSTRAINT fk_catalogo_categoria
        FOREIGN KEY (categoria_id) REFERENCES categorias(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tutor_ofertas (
    id INT NOT NULL AUTO_INCREMENT,
    tutor_id INT NOT NULL,
    materia_id INT NOT NULL,
    titulo VARCHAR(120) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    estado ENUM('borrador','publicada','pausada') NOT NULL DEFAULT 'publicada',
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ofertas_tutor (tutor_id),
    KEY idx_ofertas_materia_estado (materia_id, estado),
    CONSTRAINT fk_ofertas_tutor
        FOREIGN KEY (tutor_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ofertas_materia
        FOREIGN KEY (materia_id) REFERENCES catalogo_materias(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tutor_oferta_temas (
    id INT NOT NULL AUTO_INCREMENT,
    oferta_id INT NOT NULL,
    tema VARCHAR(150) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_oferta_temas_oferta (oferta_id),
    CONSTRAINT fk_temas_oferta
        FOREIGN KEY (oferta_id) REFERENCES tutor_ofertas(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada registro representa un bloque horario concreto publicado por un tutor.
-- Al solicitarlo se marca como 'solicitado'; al confirmar, 'reservado'.
CREATE TABLE IF NOT EXISTS disponibilidad_tutores (
    id INT NOT NULL AUTO_INCREMENT,
    tutor_id INT NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    estado ENUM('disponible','solicitado','reservado','bloqueado') NOT NULL DEFAULT 'disponible',
    PRIMARY KEY (id),
    KEY idx_disponibilidad_busqueda (tutor_id, fecha, estado),
    CONSTRAINT fk_disponibilidad_tutor
        FOREIGN KEY (tutor_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitudes de sesiones individuales. Una solicitud puede empezar como
-- consulta de tema sin horario; cuando se acuerde el tema, se selecciona
-- un bloque disponible y se crea/actualiza una solicitud de reserva.
CREATE TABLE IF NOT EXISTS solicitudes_tutorias (
    id INT NOT NULL AUTO_INCREMENT,
    alumno_id INT NOT NULL,
    tutor_id INT NOT NULL,
    oferta_id INT DEFAULT NULL,
    disponibilidad_id INT DEFAULT NULL,
    tipo ENUM('reserva','consulta_tema') NOT NULL DEFAULT 'reserva',
    tema_solicitado VARCHAR(180) NOT NULL,
    descripcion_necesidad TEXT DEFAULT NULL,
    estado ENUM('consulta_pendiente','pendiente','confirmada','rechazada','cancelada','completada') NOT NULL DEFAULT 'pendiente',
    respuesta_tutor TEXT DEFAULT NULL,
    fecha_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_solicitudes_alumno (alumno_id, estado),
    KEY idx_solicitudes_tutor (tutor_id, estado),
    KEY idx_solicitudes_horario (disponibilidad_id),
    CONSTRAINT fk_solicitudes_alumno
        FOREIGN KEY (alumno_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_solicitudes_tutor
        FOREIGN KEY (tutor_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_solicitudes_oferta
        FOREIGN KEY (oferta_id) REFERENCES tutor_ofertas(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_solicitudes_disponibilidad
        FOREIGN KEY (disponibilidad_id) REFERENCES disponibilidad_tutores(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorías iniciales. INSERT IGNORE permite ejecutar la sección de nuevo
-- sin duplicar las categorías que ya existan.
INSERT IGNORE INTO categorias (nombre, descripcion) VALUES
('Programación y desarrollo', 'Lenguajes, algoritmos y desarrollo de software.'),
('Matemáticas', 'Aritmética, álgebra, cálculo y otras áreas matemáticas.'),
('Ciencias básicas', 'Física, química y asignaturas científicas.'),
('Redes y sistemas', 'Redes, sistemas operativos y seguridad informática.'),
('Bases de datos', 'Diseño, consultas y administración de bases de datos.'),
('Idiomas', 'Aprendizaje y práctica de idiomas.');

-- Materias iniciales para que el catálogo no aparezca vacío.
-- Las materias existentes en la tabla `materias` se conservan aparte:
-- son clases antiguas y no se convierten automáticamente en ofertas 1 a 1.
INSERT IGNORE INTO catalogo_materias (categoria_id, nombre, descripcion)
SELECT c.id, v.nombre, v.descripcion
FROM categorias c
JOIN (
    SELECT 'Programación y desarrollo' AS categoria, 'Programación' AS nombre,
           'Fundamentos de programación y resolución de problemas.' AS descripcion
    UNION ALL SELECT 'Programación y desarrollo', 'Programación orientada a objetos',
           'Clases, objetos, herencia, encapsulamiento y polimorfismo.'
    UNION ALL SELECT 'Programación y desarrollo', 'Desarrollo de videojuegos',
           'Fundamentos y técnicas de desarrollo de videojuegos.'
    UNION ALL SELECT 'Matemáticas', 'Álgebra',
           'Ecuaciones, expresiones algebraicas y funciones.'
    UNION ALL SELECT 'Matemáticas', 'Cálculo',
           'Límites, derivadas e integrales.'
    UNION ALL SELECT 'Ciencias básicas', 'Física',
           'Conceptos y ejercicios de física.'
    UNION ALL SELECT 'Redes y sistemas', 'Seguridad de la información',
           'Fundamentos de seguridad informática.'
    UNION ALL SELECT 'Redes y sistemas', 'Redes de computadoras',
           'Fundamentos, configuración y diagnóstico de redes.'
    UNION ALL SELECT 'Bases de datos', 'Bases de datos',
           'Modelado, SQL y consultas.'
    UNION ALL SELECT 'Bases de datos', 'MySQL',
           'Diseño de tablas, relaciones y consultas SQL.'
    UNION ALL SELECT 'Idiomas', 'Inglés',
           'Práctica de gramática, conversación y comprensión.'
    UNION ALL SELECT 'Idiomas', 'Francés',
           'Práctica de gramática, conversación y comprensión.'
    UNION ALL SELECT 'Idiomas', 'Japonés',
           'Práctica de escritura, lectura y conversación.'
    UNION ALL SELECT 'Idiomas', 'Italiano',
           'Práctica de gramática, conversación y comprensión.'
) v ON v.categoria = c.nombre;
