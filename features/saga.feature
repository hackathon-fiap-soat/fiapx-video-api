# language: pt
# Cenários obrigatórios da SAGA (fiapx-docs/03-arquitetura/saga.md, "Cenários BDD obrigatórios"; spec §6.4).
Funcionalidade: SAGA de processamento de vídeo

  Cenário: processamento com sucesso
    Dado um vídeo "aula.mp4" enviado por "ana@exemplo.com" com cota livre
    Quando o evento de upload chegar
    E o processador responder "ProcessingStarted" e depois "VideoProcessed" com 120 frames
    Então o vídeo fica "COMPLETED" com 120 frames
    E a cota de "ana@exemplo.com" volta a 0 em uso

  Cenário: falha no ffmpeg com compensação
    Dado um vídeo em processamento
    Quando o processador responder "VideoProcessingFailed" com "FFMPEG_ERROR"
    Então a saga envia "CleanupArtifacts"
    E ao receber "ArtifactsCleaned" o vídeo fica "FAILED" com motivo "FFMPEG_ERROR"
    E a cota é liberada
    E um "SendNotification" com template "VIDEO_FAILED" é gravado na outbox

  Cenário: timeout do processamento
    Dado um vídeo em "PROCESSING" com prazo vencido
    Quando a verificação de timeouts rodar
    Então a saga entra em "COMPENSATING" e envia "CleanupArtifacts"

  Cenário: cota excedida
    Dado um usuário com 5 vídeos em processamento
    Quando o evento de upload de um sexto vídeo chegar
    Então o vídeo fica "FAILED" com motivo "QUOTA_EXCEEDED"
    E o arquivo enviado é apagado

  Cenário: upload inválido
    Dado um evento de upload de um arquivo de 600 MB
    Quando a saga validar o upload
    Então o vídeo fica "FAILED" com motivo "INVALID_UPLOAD"

  Cenário: mensagem duplicada
    Dado que a resposta "VideoProcessed" com message_id "X" já foi processada
    Quando a mesma mensagem chegar de novo
    Então nada muda no vídeo, na saga e na cota

  Cenário: resposta fora de ordem
    Dado uma saga em "COMPENSATING"
    Quando chegar um "VideoProcessed" atrasado
    Então a resposta é descartada com log de warning
    E a saga continua em "COMPENSATING"
