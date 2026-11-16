# CLAUDE.md — fiapx-video-api

Contexto para o Claude Code (e para quem chega agora). Este arquivo é carregado em toda sessão aberta neste
repositório. Ele resume o projeto e aponta para a documentação completa; não a substitui.

## O projeto

**Hackathon FIAP X** (PÓS TECH SOAT, Fase 5): processador de vídeos. O usuário envia vídeos, o sistema extrai
1 frame por segundo e devolve um zip com as imagens. Requisitos do enunciado: processar vários vídeos ao mesmo
tempo, não perder requisições em picos (fila), acesso com usuário e senha, lista de status por usuário e
e-mail em caso de erro. Entregáveis: documentação da arquitetura, script do banco, links dos repositórios e
vídeo de até 10 minutos.

Stack escolhida pelo time: AWS + Terraform, EKS + KEDA, Laravel 13 (PHP 8.4.27), Lambdas Node.js 24, Aurora
MySQL Serverless v2, SQS (Standard, FIFO e DLQ), S3 com POST pré-assinado, SAGA orquestrada com outbox,
DynamoDB, SES (`nao-responda@hackfiapf5.click`), New Relic, React + Vite no CloudFront (`/api/*` na mesma origem).

Repositórios na organização [hackathon-fiap-soat](https://github.com/hackathon-fiap-soat), clonados lado a lado na mesma pasta:

| Repositório | Papel | Stack | Etapa |
|---|---|---|---|
| [`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs) | Documentação: arquitetura, ADRs, RFCs, contratos, OpenAPI, diagramas e entrega | Markdown + Mermaid + JSON Schema | Etapa 1 ✅ |
| [`fiapx-infra`](https://github.com/hackathon-fiap-soat/fiapx-infra) | VPC, EKS, Aurora, S3, SQS, SES, ECR, API Gateway, OIDC, SSM, orquestrador de deploy e ambiente local | Terraform + Helm + docker-compose | Etapa 2 ✅ (local), Etapas 7–8 |
| [`fiapx-auth`](https://github.com/hackathon-fiap-soat/fiapx-auth) | Lambdas de cadastro, login, senha e authorizer JWT | Node.js 24 + Terraform | Etapa 3 |
| **fiapx-video-api** | API de vídeos, URLs pré-assinadas, orquestrador da SAGA e outbox | PHP 8.4.27 + Laravel 13 no EKS | Etapa 4 |
| [`fiapx-video-processor`](https://github.com/hackathon-fiap-soat/fiapx-video-processor) | Worker que extrai os frames com ffmpeg e gera o zip | PHP 8.4.27 + Laravel 13 no EKS + KEDA | Etapa 5 |
| [`fiapx-notification`](https://github.com/hackathon-fiap-soat/fiapx-notification) | E-mails pelo SES com histórico e idempotência no DynamoDB | Node.js 24 + Terraform | Etapa 6 |
| [`fiapx-web`](https://github.com/hackathon-fiap-soat/fiapx-web) | Front-end: cadastro, login, senha, upload, status e download | React + Vite + TypeScript + Tailwind | Etapa 6.5 |

## Onde está a verdade

1. **[`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs)** (versionado, `../fiapx-docs`): arquitetura C4, Domain
   Storytelling, ADRs, RFCs, contratos das mensagens (`contracts/`), OpenAPI (`api/openapi.yaml`), modelo
   de dados, ambientes, [parâmetros SSM](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/06-deploy/parametros-ssm.md) e
   [fluxo de deploy](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/06-deploy/fluxo-de-deploy.md).
2. **[Especificação](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/00-especificacao/Projeto-hackton.md)** (`fiapx-docs/00-especificacao/Projeto-hackton.md`):
   especificação completa do time (decisões, plano em etapas §12, regras §13). Na pasta de trabalho local,
   `../Projeto-hackton.md` é um link simbólico para ela.
3. **[Painel de progresso](https://claude.ai/artifact/BNesZU4D21nB7cqUoQp2rL)**: tarefas por etapa e status. Atualize ao concluir uma tarefa.

Se o código e a documentação divergirem, ou algo estiver ambíguo, **pergunte ao time** antes de assumir.
Decisão nova ou que contrarie uma existente vira ADR no `fiapx-docs`.

## Andamento (2026-11-16)

Etapas 0 (repositórios), 1 (documentação) e 2 (ambiente local) concluídas. Próxima: Etapa 3 (`fiapx-auth`).
Ordem do plano: 3 auth → 4 video-api → 5 processor → 6 notification → 6.5 web → 7 infra AWS 🛑 → 8 CI/CD →
9 observabilidade → 10 carga e entregáveis 🛑. O estado atualizado está no painel.

## Regras do time

- **Documentação antes do código.** Mudou contrato, rota, fila ou decisão? Atualize o `fiapx-docs` primeiro.
- **`README.md` sempre atualizado**: toda mudança em propósito, stack, como rodar/testar, variáveis ou links
  atualiza o README no mesmo PR.
- **Nunca commitar segredos** (`.env`, chaves JWT, credenciais). Use `.env.example` com valores fictícios.
- **`terraform apply`/`destroy` na AWS só pelo GitHub Actions** (merge na `main`, ou `deploy-all.yml` e
  `destroy-all.yml` no `fiapx-infra`, ADR-016 e ADR-018). Apply local só no LocalStack.
- **Todo merge na `main` faz deploy** do serviço (decisão do time). O ambiente `prod` é destruído fora das
  demonstrações; nesse período o deploy do merge falha ao ler o SSM, e é esperado.
- `main` protegida: só por PR, aprovação opcional, squash merge, histórico linear. Use a skill `fiapx-pr`.
- Conventional Commits; textos, README e mensagens ao usuário em **português do Brasil** com acentuação;
  identificadores de código em inglês.
- DDD + Clean Architecture: domínio sem framework nem SDK da AWS; dependências apontam para dentro.
- Testes com cobertura ≥ 80% e lint antes de cada commit.

## Ambiente local

`../fiapx-infra/local` sobe MySQL 8.0 (`localhost:3306`), LocalStack 4.14.0 (`http://localhost:4566`) e
Mailpit (UI `http://localhost:8025`, SMTP `localhost:1025`):

```bash
cd ../fiapx-infra/local && docker compose up -d --wait && ./scripts/verificar.sh
```

Para um serviço usar o LocalStack: `AWS_ENDPOINT_URL=http://localhost:4566`, `AWS_ACCESS_KEY_ID=test`,
`AWS_SECRET_ACCESS_KEY=test`, `AWS_REGION=us-east-1`. Os nomes dos recursos vêm do SSM em `/fiapx/local/...`
(mesmas chaves de produção, com `<env>` = `local`).

## Este repositório (Etapa 4)

Aplicação principal em Laravel 13, com DDD + Clean Architecture (padrão do `tech-challenge-application`,
spec §8.4 e ADR-003):

- `app/Domain` sem Laravel, Eloquent ou SDK da AWS: agregados `Video`, `ProcessingSaga` e `ProcessingQuota`.
- `app/Application`: use cases que dependem só de interfaces; `app/Infrastructure` implementa (Eloquent,
  S3, SQS, outbox); `app/Presentation` só converte HTTP/console ↔ DTO.
- Interfaces ligadas no `RepositoryServiceProvider`. VOs lançam `\InvalidArgumentException`, regras de
  negócio `\DomainException` (→ 422), inexistente → 404 pelo handler global. Sem try/catch em controller e
  use case (exceção: o loop dos consumidores).
- Endpoints: `POST /api/videos` (POST pré-assinado, 500 MB, 1 h), `GET /api/videos`, `GET /api/videos/{id}`,
  `GET /api/videos/{id}/download`, health. Usuário vem dos headers `x-user-*`, revalidando o JWT.
- **A aplicação roda autenticada e valida o JWT igual ao authorizer do `fiapx-auth`**, seguindo o
  [contrato do JWT](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/contracts/jwt/README.md):
  só RS256 com a chave pública do SSM (`/fiapx/<env>/auth/jwt_public_key`), `iss = fiapx-auth`,
  `aud = fiapx-api`, `exp` obrigatório, tolerância de 30 s e claims `sub`, `email` e `name`. Token inválido →
  401. Um teste PHPUnit roda os vetores de `fiapx-docs/contracts/jwt/vetores.json` (os mesmos do Node).
  Localmente, um token emitido pelo `npm run dev` do `fiapx-auth` precisa ser aceito aqui.
- SAGA orquestrada (ADR-008): estado em `processing_sagas`, comandos pela `outbox_messages`, consumidores
  idempotentes (`processed_messages`), `deadline_at` e `PROCESSING_TIMEOUT`, `messaging:prune` diário.
- Processos: web, `outbox:relay`, `saga:consume` (filas `video-uploaded` e `saga-replies.fifo`), cron e Job
  de migrate. Manifests com Kustomize em `k8s/`.
- O `saga:consume` descarta o `s3:TestEvent` sem erro.

**DoD da Etapa 4:** cobertura ≥ 80%; cenários BDD (Behat) da SAGA passam; fluxo local funciona com o
processor simulado.

## Skills deste repositório

| Skill | Quando usar |
|---|---|
| `contratos-de-mensagens` | ao produzir ou consumir mensagens SQS |
| `fiapx-pr` | ao abrir e mergear o PR |
