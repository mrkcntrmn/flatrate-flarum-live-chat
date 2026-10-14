#!/usr/bin/env python3
"""Disposable MariaDB plan for the Live Chat admin dashboard aggregates.

This is not a production connection. It builds the canonical message-table
shape (primary key, chat_id foreign-key index, no created_at index), loads a
history larger than seven days, and compares the bounded query with a full scan.
"""

from __future__ import annotations

import os
import subprocess
import textwrap

HOST = os.environ.get("DASHBOARD_PLAN_HOST", "127.0.0.1")
PORT = os.environ.get("DASHBOARD_PLAN_PORT", "33199")
USER = os.environ.get("DASHBOARD_PLAN_USER", "root")
PASSWORD = os.environ.get("DASHBOARD_PLAN_PASSWORD", "")
DATABASE = os.environ.get("DASHBOARD_PLAN_DATABASE", "flarum")
NOW = "2026-10-12 18:00:00"
HOUR = "2026-10-12 17:00:00"
DAY = "2026-10-11 18:00:00"
WEEK = "2026-10-05 18:00:00"


def sql(statement: str) -> str:
    env = os.environ.copy()
    if PASSWORD:
        env["MYSQL_PWD"] = PASSWORD
    completed = subprocess.run(
        [
            "mariadb",
            f"--host={HOST}",
            f"--port={PORT}",
            f"--user={USER}",
            "--batch",
            "--raw",
            "--skip-column-names",
            DATABASE,
        ],
        input=statement,
        text=True,
        capture_output=True,
        env=env,
        check=False,
    )
    if completed.returncode != 0:
        raise SystemExit(completed.stderr.strip() or completed.stdout.strip())
    return completed.stdout


def main() -> None:
    sql(
        textwrap.dedent(
            f"""
            DROP TABLE IF EXISTS flarum_neonchat_messages;
            CREATE TABLE flarum_neonchat_messages (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              message VARCHAR(32) NOT NULL,
              user_id INT UNSIGNED NULL,
              created_at DATETIME NOT NULL,
              edited_at DATETIME NULL,
              deleted_by INT UNSIGNED NULL,
              chat_id INT UNSIGNED NOT NULL,
              type TINYINT NOT NULL DEFAULT 0,
              is_readed TINYINT NOT NULL DEFAULT 0,
              ip_address VARCHAR(45) NULL,
              PRIMARY KEY (id),
              KEY flarum_neonchat_messages_chat_id_foreign (chat_id)
            ) ENGINE=InnoDB;

            INSERT INTO flarum_neonchat_messages
              (message, user_id, created_at, chat_id, type, deleted_by)
            SELECT
              'historical',
              1 + (n MOD 7),
              DATE_ADD('2024-01-01 00:00:00', INTERVAL n MINUTE),
              1 + (n MOD 46),
              IF(n MOD 50 = 0, 1, 0),
              IF(n MOD 40 = 0, 9, NULL)
            FROM (
              SELECT a.n + b.n * 10 + c.n * 100 + d.n * 1000 + e.n * 10000 AS n
              FROM
                (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
              JOIN
                (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
              JOIN
                (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c
              JOIN
                (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d
              JOIN
                (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) e
            ) seq
            WHERE n > 0;

            INSERT INTO flarum_neonchat_messages
              (message, user_id, created_at, chat_id, type, deleted_by)
            VALUES
              ('recent', 9, '{HOUR}', 1, 0, NULL),
              ('recent', 9, '2026-10-12 16:59:59', 1, 0, NULL),
              ('deleted', 4, '2026-10-12 17:30:00', 1, 0, 2),
              ('event', 9, '2026-10-12 17:45:00', 1, 1, NULL),
              ('recent', NULL, '{DAY}', 2, 0, NULL),
              ('deleted-only', 7, '2026-10-12 17:50:00', 47, 0, 1);
            """
        )
    )

    indexes = sql("SHOW INDEX FROM flarum_neonchat_messages")
    counts = sql("SELECT COUNT(*), MIN(created_at), MAX(created_at) FROM flarum_neonchat_messages")
    print("INDEXES")
    print(indexes)
    print("VOLUME")
    print(counts.strip())

    oracle = sql(
        textwrap.dedent(
            f"""
            SELECT chat_id,
              SUM(CASE WHEN type = 0 AND created_at >= '{HOUR}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type = 0 AND created_at >= '{DAY}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type = 0 AND created_at >= '{WEEK}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type <> 0 AND created_at >= '{DAY}' THEN 1 ELSE 0 END),
              COUNT(DISTINCT CASE WHEN type = 0 AND created_at >= '{DAY}' THEN user_id END),
              MAX(created_at)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND chat_id IN (1,2,46,47)
            GROUP BY chat_id
            ORDER BY chat_id;
            """
        )
    )
    bounded = sql(
        textwrap.dedent(
            f"""
            SELECT chat_id,
              SUM(CASE WHEN type = 0 AND created_at >= '{HOUR}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type = 0 AND created_at >= '{DAY}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type = 0 AND created_at >= '{WEEK}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type <> 0 AND created_at >= '{DAY}' THEN 1 ELSE 0 END),
              COUNT(DISTINCT CASE WHEN type = 0 AND created_at >= '{DAY}' THEN user_id END)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL
              AND created_at >= '{WEEK}'
              AND id >= (
                SELECT MIN(id) FROM flarum_neonchat_messages WHERE created_at >= '{WEEK}'
              )
              AND chat_id IN (1,2,46,47)
            GROUP BY chat_id
            ORDER BY chat_id;
            """
        )
    )
    activity = sql(
        textwrap.dedent(
            """
            SELECT 1, (SELECT created_at FROM flarum_neonchat_messages
                       WHERE chat_id = 1 AND deleted_by IS NULL ORDER BY id DESC LIMIT 1)
            UNION ALL
            SELECT 2, (SELECT created_at FROM flarum_neonchat_messages
                       WHERE chat_id = 2 AND deleted_by IS NULL ORDER BY id DESC LIMIT 1)
            UNION ALL
            SELECT 46, (SELECT created_at FROM flarum_neonchat_messages
                        WHERE chat_id = 46 AND deleted_by IS NULL ORDER BY id DESC LIMIT 1)
            UNION ALL
            SELECT 47, (SELECT created_at FROM flarum_neonchat_messages
                        WHERE chat_id = 47 AND deleted_by IS NULL ORDER BY id DESC LIMIT 1);
            """
        )
    )
    print("ORACLE_COUNTS_AND_MAX")
    print(oracle)
    print("BOUNDED_WINDOW")
    print(bounded)
    print("INDEXED_LAST_ACTIVITY")
    print(activity)

    def probe_floor() -> str:
        span = sql("SELECT MIN(id), MAX(id) FROM flarum_neonchat_messages").strip().split("\t")
        lo, hi = int(span[0]), int(span[1])
        floor = None
        probes = 0
        while lo <= hi and probes < 48:
            probes += 1
            mid = (lo + hi) // 2
            row = sql(
                f"SELECT id, created_at >= '{WEEK}' FROM flarum_neonchat_messages "
                f"WHERE id >= {mid} ORDER BY id ASC LIMIT 1"
            ).strip()
            if not row:
                hi = mid - 1
                continue
            ident, in_window = row.split("\t")
            ident = int(ident)
            if ident > hi:
                hi = mid - 1
                continue
            if in_window == "1":
                floor = ident
                hi = ident - 1
            else:
                lo = ident + 1
        print(f"ID_FLOOR {floor} PROBES {probes}")
        if floor is None:
            raise SystemExit("expected a seven-day id floor")
        return str(floor)

    floor = probe_floor()

    def timed(label: str, statement: str) -> None:
        elapsed = sql(
            f"SET @t = NOW(6); {statement}; SELECT TIMESTAMPDIFF(MICROSECOND, @t, NOW(6));"
        ).strip().splitlines()[-1]
        print(f"ELAPSED_US {label} {elapsed}")

    timed(
        "unbounded_group",
        textwrap.dedent(
            f"""
            SELECT chat_id,
              SUM(CASE WHEN type = 0 AND created_at >= '{HOUR}' THEN 1 ELSE 0 END),
              MAX(created_at)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND chat_id BETWEEN 1 AND 46
            GROUP BY chat_id
            """
        ),
    )
    timed(
        "bounded_window",
        textwrap.dedent(
            f"""
            SELECT chat_id,
              SUM(CASE WHEN type = 0 AND created_at >= '{HOUR}' THEN 1 ELSE 0 END)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND created_at >= '{WEEK}'
              AND id >= {floor}
              AND chat_id BETWEEN 1 AND 46
            GROUP BY chat_id
            """
        ),
    )
    timed(
        "one_chat_last_activity",
        """
        SELECT created_at FROM flarum_neonchat_messages
        WHERE chat_id = 1 AND deleted_by IS NULL
        ORDER BY id DESC LIMIT 1
        """,
    )

    print("EXPLAIN_UNBOUNDED")
    print(
        sql(
            f"""
            EXPLAIN SELECT chat_id, MAX(created_at)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND chat_id BETWEEN 1 AND 46
            GROUP BY chat_id
            """
        )
    )
    print("EXPLAIN_BOUNDED")
    print(
        sql(
            f"""
            EXPLAIN SELECT chat_id
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND created_at >= '{WEEK}' AND id >= {floor}
              AND chat_id BETWEEN 1 AND 46
            GROUP BY chat_id
            """
        )
    )
    print("EXPLAIN_LAST_ACTIVITY")
    print(
        sql(
            """
            EXPLAIN SELECT created_at FROM flarum_neonchat_messages
            WHERE chat_id = 1 AND deleted_by IS NULL
            ORDER BY id DESC LIMIT 1
            """
        )
    )
    print("DASHBOARD_METRICS_PLAN=PASS")


if __name__ == "__main__":
    main()
