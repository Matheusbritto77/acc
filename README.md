
# astarOT

astarOT is a free and open-source Automatic Account Creator (AAC) written in MVC-PHP. It supports only MySQL databases.


## Infos

- Fully Object Oriented
- Model/View/Controller (MVC)
- Middlewares
- API
- Composer
    - Fast Route
    - PhpDotEnv
    - Twig
    - Google2FA
    - GuzzleHttp
    - DiscordPHP
    - PagSeguro
    - PayPal
    - MercadoPago
    - Carbon
    - Resend SMTP
- Using .env to configure

## Instalação

Install astarOT on Debian / Ubuntu

```bash
  sudo apt install php-bcmath
  sudo apt install php-curl
  sudo apt install php-dom
  sudo apt install php-gd
  sudo apt install php-mbstring
  sudo apt install php-mysql
  sudo apt install php-pdo
  sudo apt install php-xml
  sudo apt install php-json
```
    
## Configure

- Import canaryaac.sql
-  Configure .env
- Set `RESEND_API_KEY` and `MAIL_WEB` to send site emails through Resend
- When `RESEND_API_KEY` is present, the generated mail transport prefers Resend
- Resend requires a verified sender domain or authorized sender address
## Documentação da API

#### Search Characters

```http
  POST /api/v1/searchcharacter
```

| Parâmetro   | Tipo       | Descrição                           |
| :---------- | :--------- | :---------------------------------- |
| `name` | `string` | Pesquisa de personagens. |

#### Client Login

```http
  POST /api/v1/login
```

| Parâmetro   | Tipo       | Descrição                                   |
| :---------- | :--------- | :------------------------------------------ |
| `request`      | `string` | Conexão ao client. |

## Autor

- [@lucasgiovannibr](https://www.github.com/lucasgiovannibr)
