<?php

namespace App\Utils;

class Translator {
    public static function getLang() {
        $lang = 'pt'; // Default to PT
        if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'pt', 'es'])) {
            $lang = $_GET['lang'];
            setcookie('lang', $lang, time() + 3600 * 24 * 30, '/');
            $_COOKIE['lang'] = $lang;
        } elseif (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['en', 'pt', 'es'])) {
            $lang = $_COOKIE['lang'];
        }
        return $lang;
    }

    public static function translateHtml($html) {
        $lang = self::getLang();
        if ($lang === 'en') {
            // Since source code templates are in English, we return them as is
            return $html;
        }

        $dictionary = self::getDictionary($lang);
        if (empty($dictionary)) {
            return $html;
        }

        // Tokenize HTML to avoid translating text inside HTML tags, script, and style blocks
        $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inScript = false;
        $inStyle = false;
        
        foreach ($parts as &$part) {
            if (empty($part)) continue;
            if ($part[0] === '<') {
                $tagLower = strtolower($part);
                if (strpos($tagLower, '<script') !== false) {
                    $inScript = true;
                } elseif (strpos($tagLower, '</script') !== false) {
                    $inScript = false;
                } elseif (strpos($tagLower, '<style') !== false) {
                    $inStyle = true;
                } elseif (strpos($tagLower, '</style') !== false) {
                    $inStyle = false;
                }
                continue;
            }
            if ($inScript || $inStyle) {
                continue;
            }
            
            // Translate using single-pass strtr which automatically processes longest keys first
            $part = strtr($part, $dictionary);
        }
        
        return implode('', $parts);
    }

    private static function getDictionary($lang) {
        if ($lang === 'pt') {
            return [
                // Navigation / Sidebar Menus
                'Last News' => 'Últimas Notícias',
                'News Archive' => 'Arquivo de Notícias',
                'Event Schedule' => 'Agenda de Eventos',
                'Library' => 'Biblioteca',
                'Creatures' => 'Criaturas',
                'Boostable Bosses' => 'Bosses Impulsionáveis',
                'Achievements' => 'Conquistas',
                'Experience Table' => 'Tabela de Experiência',
                'Community' => 'Comunidade',
                'Characters' => 'Personagens',
                'Worlds' => 'Mundos',
                'Highscores' => 'Rankings',
                'Last Deaths' => 'Últimas Mortes',
                'Houses' => 'Casas',
                'Guilds' => 'Guildas',
                'Polls' => 'Enquetes',
                'Account' => 'Conta',
                'Account Management' => 'Gerenciar Conta',
                'Create Account' => 'Criar Conta',
                'Download Client' => 'Baixar Cliente',
                'Lost Account' => 'Recuperar Conta',
                'Wars' => 'Guerras',
                'Active Wars' => 'Guerras Ativas',
                'Pending Wars' => 'Guerras Pendentes',
                'Surrender Wars' => 'Guerras Rendidas',
                'Support' => 'Suporte',
                'Shop' => 'Loja',
                
                // Account Sidebar / Widgets
                'Join Discord' => 'Entrar no Discord',
                'Download' => 'Baixar',
                'Server Online' => 'Servidor Online',
                'Server Offline' => 'Servidor Offline',
                'Players Online' => 'Jogadores Online',
                'View Highscores' => 'Ver Rankings',
                'Vote Now' => 'Votar Agora',
                'Donate here!' => 'Faça sua Doação!',
                'Get Coins' => 'Comprar Moedas',
                'Level' => 'Nível',
                'Today\'s boosted creature' => 'Criatura impulsionada de hoje',
                'Today\'s boosted boss' => 'Chefe impulsionado de hoje',
                
                // Downloads Page
                'Official Tibia Client' => 'Cliente Oficial do Tibia',
                'Unsupported Tibia Clients' => 'Clientes Não Suportados',
                'system requirements' => 'requisitos do sistema',
                'information' => 'informações',
                'Disclaimer' => 'Termo de Isenção',
                'Download Tibia Windows Client' => 'Baixar Cliente Tibia Windows',
                'Download Tibia Linux Client' => 'Baixar Cliente Tibia Linux',
                'Download Tibia macOS Client' => 'Baixar Cliente Tibia macOS',
                'The software and any related documentation is provided' => 'O software e qualquer documentação relacionada são fornecidos',
                'without warranty of any kind. The entire risk arising' => 'sem garantia de qualquer tipo. Todo o risco decorrente',
                'out of use of the software remains with you. In no event shall' => 'do uso do software permanece com você. Em nenhum caso a',
                'be liable for any damages to your computer or loss of data.' => 'será responsável por quaisquer danos ao seu computador ou perda de dados.',
                
                // Create Account Page
                'Create New Account' => 'Criar Nova Conta',
                'Account Name:' => 'Nome da Conta:',
                'Email Address:' => 'Endereço de E-mail:',
                'Password:' => 'Senha:',
                'Password Again:' => 'Confirmar Senha:',
                'Password Rules' => 'Regras de Senha',
                'The password must have at least 10 and less than 30 letters!' => 'A senha deve ter entre 10 e 30 caracteres!',
                'The password must contain at least one character other than' => 'A senha deve conter pelo menos um caractere que não seja',
                'The password must contain at least one lower case letter' => 'A senha deve conter pelo menos uma letra minúscula',
                'The password must contain at least one upper case letter' => 'A senha deve conter pelo menos uma letra maiúscula',
                'The password must contain at least one number' => 'A senha deve conter pelo menos um número',
                'Character Name:' => 'Nome do Personagem:',
                'Sex:' => 'Sexo:',
                'Male' => 'Masculino',
                'Female' => 'Feminino',
                'Vocation:' => 'Vocação:',
                'World Location:' => 'Localização do Mundo:',
                'World Type:' => 'Tipo de Mundo:',
                'World Name:' => 'Nome do Mundo:',
                'Suggested world:' => 'Mundo sugerido:',
                'change game world' => 'alterar mundo',
                'suggest game world' => 'sugerir mundo',
                'Please select the following check box:' => 'Por favor, marque a seguinte caixa de seleção:',
                'I agree to the Tibia Service Agreement, the Tibia Rules and the Tibia Privacy Policy.' => 'Eu concordo com o Contrato de Serviço, Regras e Política de Privacidade.',
                'Submit' => 'Enviar',
                'Back' => 'Voltar',
                
                // Account management & login
                'Forgot your password?' => 'Esqueceu sua senha?',
                'Lost Account?' => 'Recuperar Conta?',
                'Registration' => 'Registro',
                'Login' => 'Entrar',
                'Logout' => 'Sair',
                'Please enter the password again!' => 'Por favor, digite a senha novamente!',
                'Please enter your account name!' => 'Por favor, digite o nome da sua conta!',
                'Please enter your email address!' => 'Por favor, digite o seu endereço de e-mail!',
                
                // Characters / Community / Highscores
                'Rank' => 'Posição',
                'Name' => 'Nome',
                'Vocation' => 'Vocação',
                'Points' => 'Pontos',
                'Online' => 'Online',
                'Offline' => 'Offline',
                'Highscores Filter' => 'Filtro de Rankings',
                'Experience Points' => 'Pontos de Experiência',
                'Magic Level' => 'Nível Mágico',
                'Axe Fighting' => 'Luta com Machado',
                'Club Fighting' => 'Luta com Clava',
                'Sword Fighting' => 'Luta com Espada',
                'Distance Fighting' => 'Luta à Distância',
                'Shielding' => 'Defesa',
                'Fishing' => 'Pesca',
                'Fist Fighting' => 'Luta com Punhos',
            ];
        }

        if ($lang === 'es') {
            return [
                // Navigation / Sidebar Menus
                'Last News' => 'Últimas Noticias',
                'News Archive' => 'Archivo de Noticias',
                'Event Schedule' => 'Calendario de Eventos',
                'Library' => 'Biblioteca',
                'Creatures' => 'Criaturas',
                'Boostable Bosses' => 'Jefes Potenciados',
                'Achievements' => 'Logros',
                'Experience Table' => 'Tabla de Experiencia',
                'Community' => 'Comunidad',
                'Characters' => 'Personajes',
                'Worlds' => 'Mundos',
                'Highscores' => 'Clasificaciones',
                'Last Deaths' => 'Últimas Muertes',
                'Houses' => 'Casas',
                'Guilds' => 'Gremios',
                'Polls' => 'Encuestas',
                'Account' => 'Cuenta',
                'Account Management' => 'Gestión de Cuenta',
                'Create Account' => 'Crear Cuenta',
                'Download Client' => 'Descargar Cliente',
                'Lost Account' => 'Cuenta Perdida',
                'Wars' => 'Guerras',
                'Active Wars' => 'Guerras Activas',
                'Pending Wars' => 'Guerras Pendientes',
                'Surrender Wars' => 'Guerras Rendidas',
                'Support' => 'Soporte',
                'Shop' => 'Tienda',
                
                // Account Sidebar / Widgets
                'Join Discord' => 'Unirse a Discord',
                'Download' => 'Descargar',
                'Server Online' => 'Servidor Online',
                'Server Offline' => 'Servidor Offline',
                'Players Online' => 'Jugadores Activos',
                'View Highscores' => 'Ver Rankings',
                'Vote Now' => 'Votar Ahora',
                'Donate here!' => '¡Dona aquí!',
                'Get Coins' => 'Obtener Monedas',
                'Level' => 'Nivel',
                'Today\'s boosted creature' => 'Criatura potenciada de hoy',
                'Today\'s boosted boss' => 'Jefe potenciado de hoy',
                
                // Downloads Page
                'Official Tibia Client' => 'Cliente Oficial de Tibia',
                'Unsupported Tibia Clients' => 'Clientes No Soportados',
                'system requirements' => 'requisitos del sistema',
                'information' => 'información',
                'Disclaimer' => 'Aviso Legal',
                'Download Tibia Windows Client' => 'Descargar Cliente Tibia Windows',
                'Download Tibia Linux Client' => 'Descargar Cliente Tibia Linux',
                'Download Tibia macOS Client' => 'Descargar Cliente Tibia macOS',
                'The software and any related documentation is provided' => 'El software y la documentación relacionada se proporcionan',
                'without warranty of any kind. The entire risk arising' => 'sin garantía de ningún tipo. Todo el riesgo derivado',
                'out of use of the software remains with you. In no event shall' => 'del uso del software permanece con usted. En ningún caso la',
                'be liable for any damages to your computer or loss of data.' => 'será responsable de daños a su computadora o pérdida de datos.',
                
                // Create Account Page
                'Create New Account' => 'Crear Nueva Cuenta',
                'Account Name:' => 'Nombre de Cuenta:',
                'Email Address:' => 'Dirección de Correo:',
                'Password:' => 'Contraseña:',
                'Password Again:' => 'Confirmar Contraseña:',
                'Password Rules' => 'Reglas de Contraseña',
                'The password must have at least 10 and less than 30 letters!' => '¡La contraseña debe tener entre 10 y 30 caracteres!',
                'The password must contain at least one character other than' => 'La contraseña debe contener al menos un carácter que no sea',
                'The password must contain at least one lower case letter' => 'La contraseña debe contener al menos una letra minúscula',
                'The password must contain at least one upper case letter' => 'La contraseña debe contener al menos una letra mayúscula',
                'The password must contain at least one number' => 'La contraseña debe contener al menos un número',
                'Character Name:' => 'Nombre del Personaje:',
                'Sex:' => 'Sexo:',
                'Male' => 'Masculino',
                'Female' => 'Femenino',
                'Vocation:' => 'Vocación:',
                'World Location:' => 'Ubicación del Mundo:',
                'World Type:' => 'Tipo de Mundo:',
                'World Name:' => 'Nombre del Mundo:',
                'Suggested world:' => 'Mundo sugerido:',
                'change game world' => 'cambiar mundo',
                'suggest game world' => 'sugerir mundo',
                'Please select the following check box:' => 'Por favor, marque la siguiente casilla:',
                'I agree to the Tibia Service Agreement, the Tibia Rules and the Tibia Privacy Policy.' => 'Acepto el Acuerdo de Servicio, las Reglas y la Política de Privacidad de Tibia.',
                'Submit' => 'Enviar',
                'Back' => 'Volver',
                
                // Account management & login
                'Forgot your password?' => '¿Olvidó su contraseña?',
                'Lost Account?' => '¿Cuenta Perdida?',
                'Registration' => 'Registro',
                'Login' => 'Entrar',
                'Logout' => 'Salir',
                'Please enter the password again!' => '¡Por favor, introduzca la contraseña de nuevo!',
                'Please enter your account name!' => '¡Por favor, introduzca su nombre de cuenta!',
                'Please enter your email address!' => '¡Por favor, introduzca su dirección de correo electrónico!',
                
                // Characters / Community / Highscores
                'Rank' => 'Posición',
                'Name' => 'Nombre',
                'Vocation' => 'Vocación',
                'Points' => 'Puntos',
                'Online' => 'Online',
                'Offline' => 'Offline',
                'Highscores Filter' => 'Filtro de Rankings',
                'Experience Points' => 'Puntos de Experiencia',
                'Magic Level' => 'Nivel Mágico',
                'Axe Fighting' => 'Lucha con Hacha',
                'Club Fighting' => 'Lucha con Maza',
                'Sword Fighting' => 'Lucha con Espada',
                'Distance Fighting' => 'Lucha a Distancia',
                'Shielding' => 'Defensa',
                'Fishing' => 'Pesca',
                'Fist Fighting' => 'Lucha a Puño',
            ];
        }

        return [];
    }
}
