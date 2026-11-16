# Tudo o que vem de outros repositórios é lido do SSM (ADR-001, fiapx-docs/06-deploy/parametros-ssm.md).
locals {
  ssm    = "/fiapx/${var.env}"
  prefix = "fiapx-${var.env}-video-api"
}

data "aws_ssm_parameter" "this" {
  for_each = toset([
    "eks/cluster_name",
    "s3/videos_bucket",
    "sqs/video-uploaded/arn",
    "sqs/saga-replies/arn",
    "sqs/processing-commands/arn",
    "sqs/notification-commands/arn",
    "apigw/api_id",
    "apigw/vpc_link_id",
    "alb/listener_arn",
    "auth/authorizer_id",
  ])
  name = "${local.ssm}/${each.value}"
}

locals {
  p      = { for k, v in data.aws_ssm_parameter.this : k => nonsensitive(v.value) }
  bucket = local.p["s3/videos_bucket"]
}
