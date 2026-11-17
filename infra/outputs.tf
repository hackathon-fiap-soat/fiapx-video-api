output "routes" {
  description = "Rotas criadas no API Gateway."
  value       = concat(local.rotas_protegidas, local.rotas_publicas)
}
