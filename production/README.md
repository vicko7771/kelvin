# Configuração da API Pix e XTracky para Vercel

Este documento detalha as etapas necessárias para configurar e implantar a API Pix e o envio de eventos para o XTracky na plataforma Vercel.

## 1. Estrutura do Projeto

O projeto consiste nos seguintes arquivos principais:

| Arquivo | Descrição |
| :--- | :--- |
| `payments.php` | O script principal da API que lida com a criação e consulta de pagamentos Pix via Nitro Pagamentos, e o envio de eventos para o XTracky. |
| `test_xtracky.php` | Um script auxiliar (presumivelmente) para testar a integração com o XTracky. |
| `vercel.json` | O arquivo de configuração da Vercel que define como os arquivos PHP devem ser tratados e as rotas da API. |
| `nitro_debug.txt` | Arquivo de log gerado pelo script `payments.php` (deve ser ignorado no deploy). |

## 2. Configuração para Deploy na Vercel

O deploy na Vercel será feito utilizando o *Serverless Functions* com o *Vercel PHP Runtime*.

### 2.1. Variáveis de Ambiente

Para garantir a segurança e flexibilidade, as chaves de API e configurações sensíveis foram substituídas por variáveis de ambiente no arquivo `payments.php`. Você **DEVE** configurar as seguintes variáveis no painel da Vercel (em **Settings** > **Environment Variables**):

| Variável | Valor Padrão (Encontrado no Código) | Descrição |
| :--- | :--- | :--- |
| `NITRO_API_URL` | `https://api.nitropagamentos.com/api/public/v1/transactions` | URL base da API Nitro Pagamentos. |
| `NITRO_API_TOKEN` | `AP4LznUeVh1dgR6kLuqYfeiz9bMgybCOiEOBLqjQutBjlqfa1DNXARyXdHqL` | Seu token de API da Nitro Pagamentos. **Substitua pelo seu token real.** |
| `NITRO_OFFER_HASH` | `tnvei3gut8` | O hash da oferta/produto na Nitro Pagamentos. **Substitua pelo seu hash real.** |
| `XTRACKY_API_URL` | `https://api.xtracky.com/api/integrations/api` | URL da API de integração do XTracky. |

**Atenção:** É crucial que você substitua os valores de `NITRO_API_TOKEN` e `NITRO_OFFER_HASH` pelos seus valores reais no painel da Vercel.

### 2.2. Instruções de Deploy

1.  **Crie um Repositório Git:** Inicialize um repositório Git localmente na pasta `vercel-pix-api` e envie os arquivos (`payments.php`, `test_xtracky.php`, `vercel.json`) para um serviço como GitHub, GitLab ou Bitbucket.
2.  **Conecte a Vercel:** Conecte sua conta Vercel ao repositório criado.
3.  **Configure as Variáveis:** No painel da Vercel, vá para as configurações do projeto e adicione as variáveis de ambiente listadas acima.
4.  **Deploy:** A Vercel detectará o arquivo `vercel.json` e o `payments.php` e fará o deploy automaticamente usando o runtime PHP.

## 3. Funcionamento da API

A API foi configurada para ter dois endpoints principais, ambos roteados para `payments.php` pelo `vercel.json`:

### 3.1. Criação de Pagamento Pix (POST)

- **Método:** `POST`
- **URL:** `[URL_DO_SEU_DEPLOY]/`
- **Função:** Cria um novo pagamento Pix na Nitro Pagamentos e envia o evento **PIX GERADO** para o XTracky.

### 3.2. Consulta de Status de Pagamento (GET)

- **Método:** `GET`
- **URL:** `[URL_DO_SEU_DEPLOY]/?transactionHash=SEU_HASH`
- **Função:** Consulta o status de uma transação na Nitro Pagamentos. Se o status for `paid` (pago), ele envia o evento **PIX PAGO** para o XTracky.

## 4. Solução para o XTracky

O problema de "não estar funcionando" o envio de eventos para o XTracky pode estar relacionado a:

1.  **Chaves de API/Hash Incorretos:** Se o `NITRO_API_TOKEN` ou `NITRO_OFFER_HASH` estiverem errados, a transação Pix não será criada, e consequentemente, o evento XTracky não será disparado.
2.  **Problemas de Conexão:** O script utiliza `curl` para se comunicar com o XTracky. Se houver falha na conexão (timeout, erro de SSL, etc.), o evento não será enviado.
3.  **Estrutura de Dados:** O XTracky pode ter requisitos específicos de dados que não estão sendo atendidos.

A solução implementada (uso de variáveis de ambiente) garante que as chaves corretas sejam usadas. O código de log (`gravarLog` e `error_log`) no `payments.php` é robusto e deve ajudar a diagnosticar o problema após o deploy.

**Recomendação:** Após o deploy, verifique os logs da Vercel para o `payments.php`. Procure pelas mensagens de log:

- `=== ENVIANDO PARA XTRACKY ===`
- `Status HTTP: [CÓDIGO]`
- `✅ XTracky PIX Gerado: ENVIADO com sucesso` ou `❌ XTracky PIX Gerado: FALHOU`

Essas mensagens indicarão se a requisição para o XTracky está sendo feita e qual é o resultado.

---
*Documento gerado por Manus AI*
