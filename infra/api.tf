# Rotas /api/videos* e /api/health/* no HTTP API do fiapx-infra, integradas ao ALB interno pelo VPC Link
# (ADR-010). As rotas de vídeos usam o authorizer do fiapx-auth e repassam o usuário em x-user-*.
resource "aws_apigatewayv2_integration" "alb" {
  api_id                 = local.p["apigw/api_id"]
  integration_type       = "HTTP_PROXY"
  integration_method     = "ANY"
  integration_uri        = local.p["alb/listener_arn"]
  connection_type        = "VPC_LINK"
  connection_id          = local.p["apigw/vpc_link_id"]
  payload_format_version = "1.0"
  timeout_milliseconds   = 29000

  request_parameters = {
    "overwrite:header.x-user-id"    = "$context.authorizer.user_id"
    "overwrite:header.x-user-email" = "$context.authorizer.email"
    "overwrite:header.x-user-name"  = "$context.authorizer.name"
  }
}

# Health sem o mapeamento de headers do authorizer (não há contexto nessas rotas).
resource "aws_apigatewayv2_integration" "alb_publico" {
  api_id                 = local.p["apigw/api_id"]
  integration_type       = "HTTP_PROXY"
  integration_method     = "ANY"
  integration_uri        = local.p["alb/listener_arn"]
  connection_type        = "VPC_LINK"
  connection_id          = local.p["apigw/vpc_link_id"]
  payload_format_version = "1.0"
  timeout_milliseconds   = 10000
}

locals {
  rotas_protegidas = ["POST /api/videos", "GET /api/videos", "GET /api/videos/{id}", "GET /api/videos/{id}/download"]
  rotas_publicas   = ["GET /api/health/live", "GET /api/health/ready"]
}

resource "aws_apigatewayv2_route" "videos" {
  for_each           = toset(local.rotas_protegidas)
  api_id             = local.p["apigw/api_id"]
  route_key          = each.value
  target             = "integrations/${aws_apigatewayv2_integration.alb.id}"
  authorization_type = "CUSTOM"
  authorizer_id      = local.p["auth/authorizer_id"]
}

resource "aws_apigatewayv2_route" "health" {
  for_each  = toset(local.rotas_publicas)
  api_id    = local.p["apigw/api_id"]
  route_key = each.value
  target    = "integrations/${aws_apigatewayv2_integration.alb_publico.id}"
}
