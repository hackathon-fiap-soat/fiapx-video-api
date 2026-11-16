# fiapx-video-api

Aplicação principal do FIAP X: gestão de vídeos, POST pré-assinado no S3, listagem de status, download e orquestração da SAGA (outbox e consumidores idempotentes).

> Parte do **Hackathon FIAP X — Processador de Vídeos** (PÓS TECH SOAT, Fase 5).
> Etapa 4 concluída (código, testes, BDD da SAGA, imagem, manifests e Terraform); o deploy pelo GitHub
> Actions entra na Etapa 8. A documentação vem antes do código: a arquitetura, as decisões (ADRs/RFCs) e os
> contratos ficam em [`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs).

## Stack

PHP 8.4.27 · Laravel 13 · MySQL 8 (Aurora) · AWS SDK (S3, SQS, SSM) · ramsey/uuid (UUID v7) · firebase/php-jwt
(RS256) · PHPUnit + Mockery · Behat · Pint · PHPStan (Larastan, nível 6) · Docker · Kustomize (EKS) · KEDA ·
Terraform (rotas no API Gateway e role do Pod Identity)

## Arquitetura

Aplicação Laravel 13. Dentro dela, o código segue DDD + Clean Architecture ([ADR-003](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/04-adrs/ADR-003-ddd-e-clean-architecture-nos-servicos-laravel.md)),
com as dependências apontando para dentro:

```text
app/Domain/          regras de negócio sem importar o Laravel (ADR-003): Video, ProcessingSaga (máquina de estados), ProcessingQuota, VOs e interfaces
app/Application/     casos de uso, portas (ObjectStorage, CommandBus, TransactionManager, MessageDeduplicator),
                     DTOs e a MessageFactory (envelope dos contratos v1)
app/Infrastructure/  Eloquent (lock otimista na saga), outbox + relay, SQS, S3, JWT, SSM, relógio e UUID
app/Presentation/    controllers, FormRequests, middlewares e comandos de console
app/Providers/RepositoryServiceProvider.php   liga cada interface à implementação
```

Sem try/catch em controllers e casos de uso: o handler global em `bootstrap/app.php` converte as exceções
(VO inválido → 422, `NotFoundException` → 404, `ConflictException` → 409, `\DomainException` → 422, token
inválido → 401). A exceção é o laço do `saga:consume`, que decide entre confirmar a mensagem e deixá-la
voltar para a fila.

## Rotas

Contrato em [`fiapx-docs/api/openapi.yaml`](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/api/openapi.yaml);
um teste valida as respostas reais contra ele.

| Rota | Resposta |
|---|---|
| `POST /api/videos` `{filename, size_bytes, content_type}` | `201 {id, status: PENDING_UPLOAD, upload: {url, fields, expires_at}}`: POST pré-assinado de 1 h, com `content-length-range` até o tamanho declarado (máx. 500 MB) |
| `GET /api/videos?status=&page=&per_page=` | `200 {data: [Video], meta: {page, per_page, total}}`, só os vídeos do usuário, mais recentes primeiro |
| `GET /api/videos/{id}` | `200 Video`; `404` se não existe ou é de outro usuário |
| `GET /api/videos/{id}/download` | `200 {url, expires_at}` (GET pré-assinado de 15 min) se `COMPLETED`; senão `409` |
| `GET /api/health/live`, `GET /api/health/ready` | probes; o `ready` testa o banco e o SQS (`503` se algum falhar) |

**Autenticação:** a aplicação revalida o JWT do header `Authorization` com as mesmas regras e os mesmos vetores
de teste do authorizer do `fiapx-auth` ([contrato do JWT](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/contracts/jwt/README.md)):
só RS256, `iss = fiapx-auth`, `aud = fiapx-api`, `exp` obrigatório, tolerância de 30 s, claims `sub`, `email` e
`name`. Se o API Gateway mandar `x-user-id`, ele precisa bater com o `sub`. Falha → `401`.

## SAGA

Orquestrada aqui ([ADR-008](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/04-adrs/ADR-008-saga-orquestrada-com-outbox-e-consumidores-idempotentes.md),
[saga.md](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/03-arquitetura/saga.md)):

- **Início:** o evento do S3 (fila `video-uploaded`) dispara `HeadObject` e, numa transação, ValidateUpload,
  ReserveQuota (até 5 por usuário), `ProcessVideo` na outbox e `processed_messages`. O `correlation_id` da saga é
  o `MessageId` da mensagem SQS do evento. Falha local → vídeo `FAILED`, `VIDEO_FAILED` na outbox e o arquivo
  apagado depois do commit.
- **Respostas** (`saga-replies.fifo`): `ProcessingStarted` (renova o prazo de 30 min), `VideoProcessed` (vídeo
  `COMPLETED`, cota liberada, `VIDEO_COMPLETED` se `FIAPX_NOTIFY_ON_COMPLETED=true`), `VideoProcessingFailed` →
  `CleanupArtifacts` → `ArtifactsCleaned` → cota liberada, vídeo `FAILED`, `VIDEO_FAILED`; `NotificationSent` → `NOTIFIED`.
- **Robustez:** outbox publicada pelo `outbox:relay` com `FOR UPDATE SKIP LOCKED`; deduplicação por `message_id`;
  lock otimista (`version`) na saga; resposta fora de ordem descartada com warning (sem DLQ); `s3:TestEvent`
  descartado.
- **Agendados** (`schedule:run` a cada minuto): `saga:check-timeouts` (1 min), `videos:expire-pending` (5 min) e
  `messaging:prune` (diário, 7 dias).

## Como rodar

Pré-requisitos: Docker e os repositórios `fiapx-infra` e `fiapx-docs` clonados ao lado deste. O PHP roda no
container (8.4.27); não precisa de PHP na máquina.

```bash
# 1. Ambiente local (MySQL + LocalStack + Mailpit)
(cd ../fiapx-infra/local && docker compose up -d --wait)

# 2. Dependências, chave e tabelas
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate

# 3. API em http://localhost:8080 + saga-consumer + outbox-relay + agendador + processor simulado
docker compose --profile simulador up -d
```

O token vem do `fiapx-auth` local (`npm run dev` na porta 3001):

```bash
TOKEN=$(curl -s -X POST localhost:3001/api/auth/login -H 'content-type: application/json' \
  -d '{"email":"maria@example.com","password":"Senha-forte-123!"}' | jq -r .access_token)
curl -X POST localhost:8080/api/videos -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' \
  -d '{"filename":"aula.mp4","size_bytes":2048,"content_type":"video/mp4"}'
# envie o arquivo para upload.url com todos os upload.fields e o campo file por último; depois:
curl localhost:8080/api/videos -H "authorization: Bearer $TOKEN"
```

**Processor simulado** (`processor:simulate`, só com `APP_ENV=local`): até a Etapa 5, responde aos comandos
como o `fiapx-video-processor` vai responder. Gera um zip com 3 PNGs de 1×1 pixel. Um arquivo com menos de
8 bytes simula vídeo corrompido (`VideoProcessingFailed`, `FFMPEG_ERROR`).

## Como testar

```bash
docker compose run --rm app composer lint            # pint --test + phpstan
docker compose run --rm app composer test            # todas as suítes do PHPUnit
docker compose run --rm app composer test:coverage   # com cobertura mínima de 80% (é o que o CI roda)
docker compose run --rm app composer bdd             # Behat: os 7 cenários obrigatórios da SAGA
```

| Suíte | O que cobre |
|---|---|
| `Unit` | domínio (agregados, VOs, máquina de estados) e casos de uso com Mockery |
| `Feature` | rotas HTTP com MySQL (cada teste numa transação desfeita) e S3 em memória |
| `Contract` | mensagens produzidas × JSON Schemas, exemplos consumidos, respostas × OpenAPI e os vetores do JWT |
| `Integration` | POST pré-assinado real no S3 do LocalStack, relay, consumidor e o fluxo completo com o simulador |
| `features/` (Behat) | sucesso, falha no ffmpeg com compensação, timeout, cota excedida, upload inválido, mensagem duplicada e resposta fora de ordem |

As suítes `Feature`, `Contract` e `Integration` e o Behat precisam do ambiente local de pé. A `Integration` grava
de verdade e apaga o que criou.

## Variáveis de ambiente

| Variável | Uso | Padrão |
|---|---|---|
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Usuário `fiapx_video` (só acesso ao `fiapx_video`) | `.env.example` |
| `FIAPX_ENV` | Prefixo dos parâmetros `/fiapx/<env>/...` | `local` |
| `AWS_ENDPOINT_URL` | Endpoint interno da AWS (LocalStack) | vazio na AWS |
| `AWS_S3_PUBLIC_ENDPOINT` | Endpoint das URLs pré-assinadas usadas pelo navegador | `AWS_ENDPOINT_URL` |
| `S3_VIDEOS_BUCKET`, `SQS_*_URL`, `JWT_PUBLIC_KEY` | Recursos e chave pública; vazios = lidos do SSM | SSM |
| `FIAPX_NOTIFY_ON_COMPLETED` | Envia o e-mail `VIDEO_COMPLETED` (opcional na documentação) | `true` |
| `FIAPX_PROCESSING_TIMEOUT_SECONDS` | Prazo do processador | `1800` |
| `FIAPX_MAX_PARALLEL_PER_USER` | Cota de processamentos simultâneos | `5` |
| `FIAPX_CONSUMER_WAIT_SECONDS` | Long polling de cada fila no `saga:consume` | `5` |
| `NEW_RELIC_ENABLED`, `NEW_RELIC_LICENSE_KEY` | Agente New Relic (só na imagem de produção) | desligado |

## Imagem e Kubernetes

- `Dockerfile` multi-stage: `dev` (composer, pcov) e `prod` (`php:8.4.27-fpm-alpine`, extensões `pdo_mysql`,
  `opcache`, `intl`, `pcntl` e `zip`, agente New Relic, usuário `www-data` e `HEALTHCHECK`). A imagem de produção é
  `linux/amd64`, porque o agente New Relic para Alpine só existe para x86_64.
- `k8s/base` + `k8s/overlays/prod` (Kustomize): Deployments `video-api-web` (php-fpm + nginx, HPA de 2 a 6 por
  CPU 70%), `video-api-outbox-relay` (1 a 2) e `video-api-saga-consumer` (KEDA de 1 a 5 pelas duas filas), CronJob
  `schedule:run`, Job `migrate`, Service, `TargetGroupBinding`, PDB e ServiceAccount (Pod Identity). O deploy
  gera `aws.env` a partir do SSM e o Secret `fiapx-video-api-db` a partir do Secrets Manager (Etapa 8).

## Infraestrutura (`infra/`)

- Role dos pods pelo EKS Pod Identity, com o mínimo: S3 em `uploads/*` (POST pré-assinado, HeadObject e
  DeleteObject) e leitura em `results/*`; SQS para consumir `video-uploaded` e `saga-replies` e publicar em
  `processing-commands` e `notification-commands`; leitura do SSM em `/fiapx/<env>/*`.
- Rotas no HTTP API, integradas ao ALB interno pelo VPC Link: `/api/videos*` com o authorizer do `fiapx-auth`
  (`/auth/authorizer_id`) e os headers `x-user-*` vindos do contexto; `/api/health/*` sem authorizer.
- O CI roda `terraform fmt -check` e `validate`; `plan` e `apply` entram na Etapa 8.

## Links

- Documentação: [`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs)
- Todos os repositórios: [hackathon-fiap-soat](https://github.com/hackathon-fiap-soat)

## Contribuição

- A branch `main` é protegida: toda mudança entra por Pull Request (a aprovação é opcional; qualquer integrante, inclusive o autor, pode fazer o merge).
- O `README.md` deve estar sempre atualizado: toda mudança que afete propósito, stack, como rodar/testar,
  variáveis de ambiente ou links atualiza este arquivo no mesmo PR.
- Nunca commitar segredos (`.env`, chaves, tokens). Use `.env.example` com valores fictícios.
- Contexto para o Claude Code: [`CLAUDE.md`](CLAUDE.md) (projeto, regras e o papel deste repositório) e skills em
  [`.claude/skills/`](.claude/skills/) com os procedimentos deste repositório.

## Licença

[MIT](LICENSE)
