<?php

/*
 * Configuração do FIAP X. Cada valor vem de variável de ambiente; quando ela está vazia, o
 * ParameterResolver busca no SSM em /fiapx/<FIAPX_ENV>/... (ADR-001, fiapx-docs/06-deploy/parametros-ssm.md).
 * Na AWS, o deploy preenche as variáveis a partir do SSM, então a aplicação não consulta o SSM por requisição.
 */
return [
    'env' => env('FIAPX_ENV', 'local'),

    'aws' => [
        'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        // Endpoint interno (LocalStack no ambiente local). Vazio na AWS.
        'endpoint' => env('AWS_ENDPOINT_URL'),
        // Endpoint usado nas URLs pré-assinadas entregues ao navegador (ex.: http://localhost:4566).
        'public_endpoint' => env('AWS_S3_PUBLIC_ENDPOINT', env('AWS_ENDPOINT_URL')),
    ],

    // [variável de ambiente, chave SSM relativa a /fiapx/<env>/]
    'parameters' => [
        'bucket' => [env('S3_VIDEOS_BUCKET'), 's3/videos_bucket'],
        'video_uploaded_queue_url' => [env('SQS_VIDEO_UPLOADED_URL'), 'sqs/video-uploaded/url'],
        'saga_replies_queue_url' => [env('SQS_SAGA_REPLIES_URL'), 'sqs/saga-replies/url'],
        'processing_commands_queue_url' => [env('SQS_PROCESSING_COMMANDS_URL'), 'sqs/processing-commands/url'],
        'notification_commands_queue_url' => [env('SQS_NOTIFICATION_COMMANDS_URL'), 'sqs/notification-commands/url'],
        'jwt_public_key' => [env('JWT_PUBLIC_KEY'), 'auth/jwt_public_key'],
    ],

    'saga' => [
        'processing_timeout_seconds' => (int) env('FIAPX_PROCESSING_TIMEOUT_SECONDS', 1800),
        // VIDEO_COMPLETED é opcional na documentação (saga.md, passo 5): ligado por padrão.
        'notify_on_completed' => filter_var(env('FIAPX_NOTIFY_ON_COMPLETED', true), FILTER_VALIDATE_BOOL),
        'upload_ttl_seconds' => 3600,
        'download_ttl_seconds' => 900,
        'max_parallel_per_user' => (int) env('FIAPX_MAX_PARALLEL_PER_USER', 5),
        'pending_upload_ttl_seconds' => 3600,
        'retention_days' => 7,
    ],

    'consumer' => [
        // Long polling: a mesma instância alterna entre video-uploaded e saga-replies.fifo.
        'wait_seconds' => (int) env('FIAPX_CONSUMER_WAIT_SECONDS', 5),
        'max_messages' => 10,
    ],

    'relay' => [
        'batch_size' => 10,
        'idle_sleep_ms' => (int) env('FIAPX_RELAY_IDLE_MS', 500),
    ],
];
