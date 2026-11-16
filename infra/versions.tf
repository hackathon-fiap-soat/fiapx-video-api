terraform {
  required_version = ">= 1.10"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.73"
    }
  }

  # Configuração parcial: bucket, key e região chegam no `terraform init -backend-config=...` do CI
  # (o bucket do state é criado pelo fiapx-infra/bootstrap). Lock nativo do S3 (ADR-002).
  backend "s3" {
    use_lockfile = true
    encrypt      = true
  }
}

provider "aws" {
  region = var.region

  default_tags {
    tags = {
      Project     = "fiapx"
      Environment = var.env
      Repository  = "fiapx-video-api"
      ManagedBy   = "terraform"
    }
  }
}
