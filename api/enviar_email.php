<?php
// api/enviar_email.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ajuste o caminho se a pasta PHPMailer estiver em outro lugar
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// --- FUNÇÃO 1: E-MAIL DE ATIVAÇÃO (CADASTRO) ---
function enviarEmailAtivacao($emailDestino, $nomeDestino, $token) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'nao-responda@consigneiapp.com.br';
        $mail->Password   = '85E8a9c0-'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('nao-responda@consigneiapp.com.br', 'Consignei App');
        $mail->addAddress($emailDestino, $nomeDestino);

        $mail->isHTML(true);
        $mail->Subject = 'Ative sua conta no Consignei';
        
        // Link de Ativação (Mantive na API pois o ativar.php deve estar na pasta API)
        $link = "https://consigneiapp.com.br/api/ativar.php?token=" . $token;

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
            <h2 style='color: #d4af37; text-align: center;'>Bem-vindo ao Consignei!</h2>
            <p>Olá, <strong>$nomeDestino</strong>.</p>
            <p>Clique abaixo para ativar sua conta:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$link' style='background-color: #d4af37; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Ativar Minha Conta</a>
            </div>
        </div>";
        $mail->AltBody = "Ative sua conta: $link";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// --- FUNÇÃO 2: E-MAIL DE RECUPERAÇÃO (CORRIGIDA) ---
function enviarEmailRecuperacao($emailDestino, $token) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'nao-responda@consigneiapp.com.br';
        $mail->Password   = '85E8a9c0-'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('nao-responda@consigneiapp.com.br', 'Consignei App');
        $mail->addAddress($emailDestino);

        $mail->isHTML(true);
        $mail->Subject = 'Redefinir Senha - Consignei';
        
        // --- AQUI ESTÁ A CORREÇÃO: Adicionei /public/ ---
        $link = "https://consigneiapp.com.br/public/nova_senha.html?token=" . $token;

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
            <h2 style='color: #d4af37; text-align: center;'>Esqueceu sua senha?</h2>
            <p>Recebemos uma solicitação para redefinir a senha da sua loja.</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$link' style='background-color: #d4af37; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Redefinir Minha Senha</a>
            </div>
            <p style='font-size: 12px; color: #999; text-align: center;'>Válido por 1 hora.</p>
        </div>";

        $mail->AltBody = "Redefinir senha: $link";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>