output "pod_role_arn" {
  description = "Role dos pods do video-api (Pod Identity)."
  value       = aws_iam_role.pods.arn
}

output "routes" {
  description = "Rotas criadas no API Gateway."
  value       = concat(local.rotas_protegidas, local.rotas_publicas)
}
