-- Modelo relacional propuesto para una futura versión Laravel/MySQL.
CREATE DATABASE IF NOT EXISTS medrano_inventario CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE medrano_inventario;

CREATE TABLE motocicletas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    marca VARCHAR(80) NOT NULL,
    modelo VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_motocicleta (marca, modelo)
) ENGINE=InnoDB;

CREATE TABLE productos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(30) NOT NULL UNIQUE,
    nombre VARCHAR(120) NOT NULL,
    categoria VARCHAR(60) NOT NULL,
    marca VARCHAR(70),
    precio DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    stock_minimo INT UNSIGNED NOT NULL DEFAULT 0,
    ubicacion VARCHAR(60),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (precio >= 0)
) ENGINE=InnoDB;

CREATE TABLE compatibilidades (
    producto_id BIGINT UNSIGNED NOT NULL,
    motocicleta_id BIGINT UNSIGNED NOT NULL,
    fuente_validacion VARCHAR(200) NULL,
    PRIMARY KEY (producto_id, motocicleta_id),
    CONSTRAINT fk_compat_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_compat_moto FOREIGN KEY (motocicleta_id) REFERENCES motocicletas(id)
) ENGINE=InnoDB;

CREATE TABLE equivalencias (
    producto_id BIGINT UNSIGNED NOT NULL,
    alternativo_id BIGINT UNSIGNED NOT NULL,
    observacion VARCHAR(200),
    PRIMARY KEY (producto_id, alternativo_id),
    CONSTRAINT fk_equiv_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_equiv_alterno FOREIGN KEY (alternativo_id) REFERENCES productos(id),
    CHECK (producto_id <> alternativo_id)
) ENGINE=InnoDB;

CREATE TABLE movimientos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('entrada','salida') NOT NULL,
    cantidad INT UNSIGNED NOT NULL,
    stock_anterior INT UNSIGNED NOT NULL,
    stock_nuevo INT UNSIGNED NOT NULL,
    motivo VARCHAR(180) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CHECK (cantidad > 0)
) ENGINE=InnoDB;
-- En MySQL, ejecutar las entradas/salidas dentro de transacciones
-- BEGIN; SELECT stock ... FOR UPDATE; UPDATE productos; INSERT movimientos; COMMIT;
