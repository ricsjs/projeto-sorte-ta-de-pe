# Projeto Sorte Tá de Pé

Loja de números da sorte com e-book. O cliente cria uma conta, escolhe quantos números quer e paga. O sistema reserva números do sorteio ativo e envia tudo por e-mail com o e-book em anexo. O operador acompanha as vendas e exporta o retorno para a Capemisa.

- **Backend:** PHP 8.1+ puro, sem Composer (`backend-php/`)
- **Frontend:** React com Vite (`frontend/`). O build gera arquivos estáticos, que o próprio PHP entrega
- **Banco:** SQLite (padrão) ou MySQL
- **Pagamento:** InfinitePay (Checkout Integrado) ou Asaas (PIX). Para demonstração, existe um modo simulado
- **E-mail:** envio real por SMTP. WhatsApp ainda simulado

## Instalar

O repositório não traz segredos, bancos nem o build do frontend. Depois de clonar:

```
cd frontend && npm install && npm run build      # gera frontend/dist (Node 18+)
cd ../backend-php && cp .env.example .env        # preencha o .env
php -S 127.0.0.1:8000 index.php                  # local; em produção use Apache ou Nginx
```

Abra http://localhost:8000. Para desenvolver o frontend com recarga automática, rode `npm run dev` em `frontend/` com o PHP ligado.

Ficam fora do Git (ver `.gitignore`): `.env`, `*.db` e `backend-php/data/`, `node_modules/` e `frontend/dist/`.

**Requisitos do PHP:** extensões `pdo_sqlite` ou `pdo_mysql`, `curl`, `openssl`, `zip`, `mbstring` e `fileinfo`.

## Hospedagem

- **Apache:** suba `backend-php/` e `frontend/dist/` mantendo a estrutura (`frontend/dist` ao lado de `backend-php`). O `.htaccess` envia tudo para o `index.php` e bloqueia `data/`, `src/`, `ferramentas/` e `.env`.
- **Nginx:** aponte todas as rotas para `index.php`. Em PHP-FPM, o e-mail sai em segundo plano depois da resposta ao webhook.
- O frontend pode ficar em outro caminho se você definir `FRONTEND_DIST`.

## Estrutura

```
backend-php/
  index.php                 roteador da API e entrega do site
  src/app.php               regras: clientes, vendas, pagamento, sorteios, painel
  src/db.php                banco (SQLite/MySQL), .env e respostas HTTP
  src/operadores.php        login e acessos do painel
  src/infinitepay.php       Checkout Integrado da InfinitePay
  src/asaas.php             cobrança PIX do Asaas
  src/email.php             cliente SMTP próprio (STARTTLS/SSL, MIME, anexo)
  src/planilha.php          leitura de XLSX e CSV
  ferramentas/sqlite_para_mysql.php   migra os dados do SQLite para o MySQL
  assets/ebook_exemplo.pdf  e-book de exemplo (troque via EBOOK_PATH)
  lote_exemplo_capemisa.xlsx / .csv   lote de exemplo com 50 números
  numeros_exemplo.csv       lote curto (8), para testar esgotamento
frontend/src/
  Loja.jsx                  loja, cadastro, conta do cliente, pagamento
  Painel.jsx                painel do operador e do administrador
  api.js                    chamadas HTTP
```

## Conta do cliente

O comprador se cadastra uma vez, com nome, CPF validado, e-mail, WhatsApp e senha. Depois, entra com o e-mail ou o CPF e compra quantos números quiser.

- **Compra:** cada compra escolhe uma quantidade de 1 a 100, limitada ao estoque, e gera um único pagamento com o valor total.
- **Reserva:** no pagamento, todos os números da compra são reservados de uma vez. Se o estoque não cobrir, nenhum é reservado e a venda fica `sem_numero`.
- **Área do cliente:** mostra os números de cada sorteio, as compras e os pagamentos pendentes.
- **Editar cadastro:** o cliente troca o WhatsApp e a senha, sempre confirmando a senha atual. Quando a senha muda, as outras sessões são encerradas.

## Painel

O painel exige um login próprio, separado da conta de cliente.

| Perfil | Pode |
|---|---|
| Administrador | tudo o que o operador faz, mais cadastrar sorteios, criar e remover acessos e zerar a base |
| Operador | importar lotes, ver vendas e clientes, reenviar, cancelar, pausar a loja e exportar |

- **Primeiro acesso:** o primeiro administrador vem de `ADMIN_NOME`, `ADMIN_EMAIL` e `ADMIN_SENHA` no `.env`, e só é criado quando ainda não existe nenhum acesso. Depois disso, as senhas ficam no banco.
- **Sessão:** dura 12 horas. Depois de 8 tentativas de login erradas em 15 minutos, aquele IP fica bloqueado.
- **Exportação:** gera o CSV de retorno para a Capemisa, com uma linha por número e os dados do titular, e pode ser filtrada por sorteio.

## Sorteios

O painel cadastra quantos sorteios quiser, cada um com nome, data e seus próprios números. Só um fica ativo por vez.

- **Venda:** a loja vende apenas números do sorteio ativo. Sem sorteio ativo, a loja fica fechada.
- **Troca de sorteio:** cada venda guarda o sorteio em que foi feita. Um pagamento gerado antes da troca recebe números do sorteio original.
- **Importação:** o operador escolhe o sorteio que recebe o lote. O mesmo número pode existir em sorteios diferentes, mas não pode repetir dentro do mesmo sorteio.
- **Gestão:** só administradores criam, editam, ativam e removem sorteios. Só dá para remover um sorteio inativo e sem vendas.

## Pagamento

O provedor é escolhido com `PAGAMENTO` no `.env`.

### InfinitePay (`PAGAMENTO=infinitepay`)

Defina `INFINITEPAY_HANDLE` com a InfiniteTag, sem o `$`. O checkout externo precisa estar ativado no app da InfinitePay.

- **Link de pagamento:** cada compra cria um link (`POST /links`, valores em centavos). O cliente paga na página da InfinitePay, por PIX ou cartão, e volta para `/?pagamento=<order_nsu>`.
- **Confirmação:** chega pelo webhook `POST /api/webhook/infinitepay` e pela volta do cliente (`POST /api/pagamento/infinitepay/retorno`).
- **Conferência:** o webhook não tem assinatura, então todo aviso é conferido em `POST /payment_check` antes de liberar os números, e o valor pago precisa bater com o da venda.
- **Endereços:** o endereço de volta e o do webhook usam o domínio da requisição. Para fixar outro, defina `URL_PUBLICA`.

### Asaas (`PAGAMENTO=asaas`)

| Variável | Uso |
|---|---|
| `ASAAS_API_KEY` | chave da API (`$aact_...`), entre aspas simples no `.env` |
| `ASAAS_URL` | `https://api.asaas.com/v3` (produção) ou `https://api-sandbox.asaas.com/v3` |
| `ASAAS_WEBHOOK_TOKEN` | token que o Asaas manda no header `asaas-access-token` |

- **Cliente:** antes de criar um cliente no Asaas, o sistema procura pelo CPF. As notificações do Asaas ficam desligadas.
- **Cobrança:** cada compra vira uma cobrança PIX com vencimento no dia seguinte. A tela mostra o QR Code e o copia e cola.
- **Confirmação:** chega pelo webhook `POST /api/webhook/asaas` (eventos `PAYMENT_RECEIVED` e `PAYMENT_CONFIRMED`) e também pela tela do PIX, que consulta o Asaas a cada 5 segundos.
- **Limites:** o Asaas recusa cobranças abaixo de R$ 5,00. Se a conta tiver uma lista de IPs autorizados, o IP do servidor precisa estar nela.

### Simulado (`PAGAMENTO=simulado`)

A tela mostra um botão "Simular pagamento confirmado", que chama o mesmo código do webhook real. Com um provedor real ligado, a simulação fica bloqueada.

## Banco de dados: SQLite ou MySQL

| Variável | Uso |
|---|---|
| `DB_DRIVER` | `sqlite` (padrão, arquivo `data/poc.db`) ou `mysql` |
| `DB_HOST`, `DB_PORT` | servidor MySQL (padrão `127.0.0.1:3306`) |
| `DB_NAME`, `DB_USER`, `DB_PASS` | banco e usuário. O banco precisa existir. As tabelas são criadas no primeiro acesso |

Requisitos do MySQL: versão 5.7 ou 8.x (ou MariaDB 10.2+), InnoDB e utf8mb4.

Para levar os dados do SQLite para o MySQL:

1. Crie um banco vazio no MySQL e preencha `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` no `.env`.
2. Rode `php ferramentas/sqlite_para_mysql.php`. Ele cria as tabelas, copia tudo mantendo os ids e confere as contagens. Se algo falhar, nada é gravado. Ele se recusa a rodar num banco que já tenha vendas, clientes ou números.
3. Troque para `DB_DRIVER=mysql` no `.env`.

O SQL das regras é o mesmo nos dois bancos. As diferenças ficam nos helpers de `src/db.php`: `agora()`, `transacao()`, `para_update()`, `concat_numeros()` e `set_cfg()`.

## E-mail

1. Preencha os dados do SMTP no `backend-php/.env`. O `.env.example` tem modelos para Gmail (senha de app) e Resend.
2. No painel, o card "E-mail" mostra "envio real". Use "Enviar teste" para conferir.

O envio roda depois que os números são gravados, então o webhook responde rápido. Se o envio falhar, a venda fica `falha_envio`, com o motivo no painel, e o botão Reenviar tenta de novo. Sem SMTP, o e-mail roda em modo simulado.

Para trocar o e-book, use `EBOOK_PATH`. Para pôr um link do regulamento no e-mail, use `REGULAMENTO_URL`.

## Garantias

- **Sem número duplicado:** a reserva é atômica. No SQLite, usa `BEGIN IMMEDIATE`. No MySQL, trava a venda e o sorteio (`FOR UPDATE`) e repete a transação sozinho em caso de deadlock. Esse comportamento foi testado com pagamentos simultâneos.
- **Avisos repetidos:** um webhook repetido devolve os números já reservados, sem gerar outros.
- **Grava antes de enviar:** os números ficam registrados mesmo se o envio falhar.
- **Estoque esgotado:** a venda fica `sem_numero` e a loja pausa sozinha. Importar um lote novo no sorteio ativo destrava a loja.
- **Importação:** números duplicados são rejeitados linha a linha, com o motivo.

## Ainda simulado

| Parte | Hoje | Para produção |
|---|---|---|
| WhatsApp | grava a mensagem na tabela `envios` como simulada | Meta Cloud API, Z-API etc. (`entregar_whatsapp()` em `src/app.php`) |
| E-book | `assets/ebook_exemplo.pdf` de exemplo | trocar pelo roteiro real via `EBOOK_PATH` |
