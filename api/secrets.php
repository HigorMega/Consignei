<?php
// api/secrets.php

// Token da API OCR (Evita que fique exposto no código principal)
define('OCR_TOKEN', '85e8a9c0');
define('OCR_URL', 'http://72.60.8.21/ocr_api.php');

// Segurança de Domínio (Substitua pelo seu domínio real quando for para produção)
// Exemplo: define('ALLOWED_ORIGIN', 'https://consigneiapp.com.br');
define('ALLOWED_ORIGIN', '*'); 
?>