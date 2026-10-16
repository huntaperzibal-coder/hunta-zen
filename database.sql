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
