-- Script para corregir las contraseñas de los usuarios de prueba
-- Contraseña establecida para todos: Demo1234!

USE hiperlocal;

UPDATE usuarios 
SET password_hash = '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16'
WHERE email IN (
    'admin@hiperlocal.mx',
    'estetica@demo.mx',
    'perfumeria@demo.mx',
    'ana@cliente.mx',
    'luis@cliente.mx'
);
