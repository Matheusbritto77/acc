use sqlx::MySqlPool;

use crate::config::Config;

#[derive(Clone)]
pub struct AppState {
    pub pool: MySqlPool,
    pub config: Config,
}
