use std::env;

use crate::error::AppError;

#[derive(Clone, Debug)]
pub struct Config {
    pub bind: String,
    pub database_url: String,
    pub escrow_account_id: u32,
    pub internal_token: String,
    pub min_level: u32,
}

impl Config {
    pub fn from_env() -> Result<Self, AppError> {
        Ok(Self {
            bind: env::var("CHAR_BAZAAR_BIND").unwrap_or_else(|_| "127.0.0.1:8089".to_string()),
            database_url: resolve_database_url()?,
            escrow_account_id: env::var("CHAR_BAZAAR_ESCROW_ACCOUNT_ID")
                .ok()
                .and_then(|value| value.parse::<u32>().ok())
                .unwrap_or(2),
            internal_token: resolve_required_env("CHAR_BAZAAR_INTERNAL_TOKEN")?,
            min_level: env::var("CHAR_BAZAAR_MIN_LEVEL")
                .ok()
                .and_then(|value| value.parse::<u32>().ok())
                .unwrap_or(20),
        })
    }
}

fn resolve_database_url() -> Result<String, AppError> {
    if let Ok(url) = env::var("CHAR_BAZAAR_DATABASE_URL") {
        if !url.is_empty() {
            return Ok(url);
        }
    }

    let host = env::var("CANARY_DB_HOST")
        .map_err(|_| AppError::config("missing CANARY_DB_HOST or CHAR_BAZAAR_DATABASE_URL"))?;
    let port = env::var("CANARY_DB_PORT").unwrap_or_else(|_| "3306".to_string());
    let name = env::var("CANARY_DB_NAME")
        .map_err(|_| AppError::config("missing CANARY_DB_NAME or CHAR_BAZAAR_DATABASE_URL"))?;
    let user = env::var("CANARY_DB_USER")
        .map_err(|_| AppError::config("missing CANARY_DB_USER or CHAR_BAZAAR_DATABASE_URL"))?;
    let password = env::var("CANARY_DB_PASSWORD")
        .map_err(|_| AppError::config("missing CANARY_DB_PASSWORD or CHAR_BAZAAR_DATABASE_URL"))?;

    Ok(format!("mysql://{user}:{password}@{host}:{port}/{name}"))
}

fn resolve_required_env(name: &str) -> Result<String, AppError> {
    match env::var(name) {
        Ok(value) if !value.trim().is_empty() => Ok(value),
        _ => Err(AppError::config(format!("missing required {name}"))),
    }
}
