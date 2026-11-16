# Role dos pods (web, outbox-relay, saga-consumer, cron, migrate) pelo EKS Pod Identity, com o mínimo
# necessário (fiapx-docs/03-arquitetura/seguranca.md). O KEDA usa a mesma identidade (identityOwner: workload).
data "aws_iam_policy_document" "assume_pod_identity" {
  statement {
    actions = ["sts:AssumeRole", "sts:TagSession"]
    principals {
      type        = "Service"
      identifiers = ["pods.eks.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "pods" {
  name               = local.prefix
  assume_role_policy = data.aws_iam_policy_document.assume_pod_identity.json
}

data "aws_iam_policy_document" "pods" {
  # POST pré-assinado (o upload é assinado por esta role) e HeadObject/DeleteObject do vídeo enviado.
  statement {
    sid       = "UploadsDoBucket"
    actions   = ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"]
    resources = ["arn:aws:s3:::${local.bucket}/uploads/*"]
  }
  # GET pré-assinado do zip.
  statement {
    sid       = "ResultadosDoBucket"
    actions   = ["s3:GetObject"]
    resources = ["arn:aws:s3:::${local.bucket}/results/*"]
  }
  statement {
    sid       = "ConsumirEventosERespostas"
    actions   = ["sqs:ReceiveMessage", "sqs:DeleteMessage", "sqs:ChangeMessageVisibility", "sqs:GetQueueAttributes"]
    resources = [local.p["sqs/video-uploaded/arn"], local.p["sqs/saga-replies/arn"]]
  }
  statement {
    sid       = "PublicarComandos"
    actions   = ["sqs:SendMessage", "sqs:GetQueueAttributes"]
    resources = [local.p["sqs/processing-commands/arn"], local.p["sqs/notification-commands/arn"]]
  }
  # Fallback da configuração quando uma variável não vem preenchida pelo deploy.
  statement {
    sid       = "LerParametros"
    actions   = ["ssm:GetParameter"]
    resources = ["arn:aws:ssm:${var.region}:${data.aws_caller_identity.atual.account_id}:parameter${local.ssm}/*"]
  }
}

data "aws_caller_identity" "atual" {}

resource "aws_iam_role_policy" "pods" {
  name   = "acesso"
  role   = aws_iam_role.pods.id
  policy = data.aws_iam_policy_document.pods.json
}

resource "aws_eks_pod_identity_association" "pods" {
  cluster_name    = local.p["eks/cluster_name"]
  namespace       = var.namespace
  service_account = var.service_account
  role_arn        = aws_iam_role.pods.arn
}
