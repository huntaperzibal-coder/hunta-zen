PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS schema_versions (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS posts (
 id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '',
 slug TEXT NOT NULL UNIQUE, excerpt TEXT NOT NULL DEFAULT '', cover TEXT NOT NULL DEFAULT '',
 telegram_text TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
 revision INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, published_at TEXT
);
CREATE INDEX IF NOT EXISTS posts_public ON posts(status,published_at DESC);
CREATE TABLE IF NOT EXISTS telegram_deliveries (
 post_id INTEGER NOT NULL REFERENCES posts(id), channel TEXT NOT NULL, message_id INTEGER,
 state TEXT NOT NULL CHECK(state IN ('sending','sent','failed','unknown')), error TEXT NOT NULL DEFAULT '',
 updated_at TEXT NOT NULL, PRIMARY KEY(post_id,channel)
);
CREATE TABLE IF NOT EXISTS login_attempts (address TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, blocked_until INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY, action TEXT NOT NULL, post_id INTEGER, created_at TEXT NOT NULL);
INSERT OR IGNORE INTO schema_versions VALUES(1,datetime('now'));
INSERT OR IGNORE INTO settings VALUES('title','Гена и Винни');
INSERT OR IGNORE INTO settings VALUES('description','Разговоры о жизни и обо всём, что в ней происходит.');
INSERT OR IGNORE INTO settings VALUES('telegram_url','');
INSERT OR IGNORE INTO settings VALUES('indexed','0');

CREATE TABLE IF NOT EXISTS orda_users (
 id INTEGER PRIMARY KEY, login TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS orda_auth_attempts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, key_hash TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_orda_auth_attempts_key ON orda_auth_attempts(scope,key_hash,created_at);
INSERT OR IGNORE INTO schema_versions VALUES(2,datetime('now'));

CREATE TABLE IF NOT EXISTS categories (
 id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, title TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS post_categories (
 post_id INTEGER PRIMARY KEY REFERENCES posts(id) ON DELETE CASCADE,
 category_id INTEGER NOT NULL REFERENCES categories(id)
);
CREATE INDEX IF NOT EXISTS posts_by_category ON post_categories(category_id,post_id);
INSERT OR IGNORE INTO categories(slug,title,sort_order) VALUES('vinnie','Диалоги Винни',10);
INSERT OR IGNORE INTO categories(slug,title,sort_order) VALUES('gena','Диалоги Гены',20);
INSERT OR IGNORE INTO schema_versions VALUES(3,datetime('now'));

CREATE TABLE IF NOT EXISTS illustration_jobs (
 id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
 revision INTEGER NOT NULL, snapshot TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'queued',
 plan TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '', error TEXT NOT NULL DEFAULT '',
 cost REAL NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS illustration_one_active ON illustration_jobs(post_id) WHERE state IN ('queued','planning','generating');
INSERT OR IGNORE INTO schema_versions(version,applied_at) VALUES(4,datetime('now'));

CREATE TABLE IF NOT EXISTS illustration_remote_leases (
 job_id INTEGER PRIMARY KEY REFERENCES illustration_jobs(id) ON DELETE CASCADE,
 token_hash TEXT NOT NULL, created_at TEXT NOT NULL
);
INSERT OR IGNORE INTO schema_versions(version,applied_at) VALUES(5,datetime('now'));

CREATE TABLE IF NOT EXISTS telegram_parts (
 post_id INTEGER NOT NULL REFERENCES posts(id), channel TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('photo','text')),
 ordinal INTEGER NOT NULL, message_id INTEGER, state TEXT NOT NULL DEFAULT 'failed',
 operation TEXT NOT NULL DEFAULT 'upsert', content_hash TEXT NOT NULL DEFAULT '',
 PRIMARY KEY(post_id,channel,kind,ordinal)
);
CREATE TABLE IF NOT EXISTS telegram_schedule (
 id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL REFERENCES posts(id), channel TEXT NOT NULL,
 bot_fingerprint TEXT NOT NULL, snapshot TEXT NOT NULL, due_at INTEGER NOT NULL,
 state TEXT NOT NULL DEFAULT 'scheduled' CHECK(state IN ('scheduled','running','sent','failed','cancelled')),
 error TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS telegram_one_scheduled ON telegram_schedule(post_id,channel) WHERE state IN ('scheduled','running');
INSERT OR IGNORE INTO schema_versions VALUES(6,datetime('now'));

CREATE TABLE IF NOT EXISTS reader_feedback (
 id INTEGER PRIMARY KEY, submission TEXT NOT NULL UNIQUE, message TEXT NOT NULL, contact TEXT NOT NULL DEFAULT '',
 client_key TEXT NOT NULL, created_at INTEGER NOT NULL, read_at INTEGER
);
CREATE INDEX IF NOT EXISTS reader_feedback_rate ON reader_feedback(client_key,created_at);
INSERT OR IGNORE INTO schema_versions VALUES(7,datetime('now'));

CREATE TABLE IF NOT EXISTS reader_feedback_consents (
 feedback_id INTEGER PRIMARY KEY REFERENCES reader_feedback(id) ON DELETE CASCADE,
 version TEXT NOT NULL, accepted_at INTEGER NOT NULL, document_hash TEXT NOT NULL, document TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS reader_feedback_attempts (
 client_key TEXT NOT NULL, bucket INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
 PRIMARY KEY(client_key,bucket)
);
INSERT OR IGNORE INTO schema_versions VALUES(8,datetime('now'));

CREATE TABLE IF NOT EXISTS post_telegram_options (
 post_id INTEGER PRIMARY KEY REFERENCES posts(id) ON DELETE CASCADE,
 footer_mode TEXT NOT NULL DEFAULT 'auto' CHECK(footer_mode IN ('auto','on','off'))
);
INSERT OR IGNORE INTO schema_versions VALUES(9,datetime('now'));

-- One-time launch, explicitly authorized by the author. Later manual opt-out persists.
UPDATE settings SET value='1' WHERE key='indexed' AND NOT EXISTS (SELECT 1 FROM schema_versions WHERE version=10);
INSERT OR IGNORE INTO schema_versions VALUES(10,datetime('now'));
