# fiapx-video-api

Aplicação principal do FIAP X: gestão de vídeos, POST pré-assinado no S3, listagem de status, download e orquestração da SAGA (outbox e consumidores idempotentes).

> Parte do **Hackathon FIAP X — Processador de Vídeos** (PÓS TECH SOAT, Fase 5).
> 🚧 Em construção. A documentação vem antes do código: a arquitetura, as decisões (ADRs/RFCs) e os
> contratos ficam em [`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs).

## Stack

PHP 8.4.27 + Laravel 13 + Docker + manifests Kubernetes (EKS)

## Como rodar

_A definir nas próximas etapas do plano._

## Links

- Documentação: [`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs)
- Todos os repositórios: [hackathon-fiap-soat](https://github.com/hackathon-fiap-soat)

## Contribuição

- A branch `main` é protegida: toda mudança entra por Pull Request com revisão de um code owner.
- O `README.md` deve estar sempre atualizado: toda mudança que afete propósito, stack, como rodar/testar,
  variáveis de ambiente ou links atualiza este arquivo no mesmo PR.
- Nunca commitar segredos (`.env`, chaves, tokens). Use `.env.example` com valores fictícios.

## Licença

[MIT](LICENSE)
