variable "env" {
  description = "Ambiente (prefixo dos recursos e dos parâmetros /fiapx/<env>/...)."
  type        = string
  default     = "prod"
}

variable "region" {
  description = "Região da AWS."
  type        = string
  default     = "us-east-1"
}

variable "namespace" {
  description = "Namespace do Kubernetes (k8s/base)."
  type        = string
  default     = "fiapx"
}

variable "service_account" {
  description = "ServiceAccount dos pods do video-api (k8s/base/serviceaccount.yaml)."
  type        = string
  default     = "fiapx-video-api"
}
