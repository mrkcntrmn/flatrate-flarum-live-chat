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
    prove_timestamp_inversion()
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
              AND chat_id IN (1,2,46,47)
            GROUP BY chat_id
            ORDER BY chat_id;
            """
        )
    )
    activity = sql(
        textwrap.dedent(
            """
            SELECT chat_id, MAX(created_at)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND chat_id IN (1,2,46,47)
            GROUP BY chat_id
            ORDER BY chat_id;
            """
        )
    )
    def table(text: str) -> dict[str, list[str]]:
        parsed: dict[str, list[str]] = {}
        for line in text.strip().splitlines():
            parts = line.split("\t")
            parsed[parts[0]] = parts[1:]
        return parsed

    oracle_rows = table(oracle)
    window_rows = table(bounded)
    activity_rows = table(activity)
    if not oracle_rows or not window_rows or not activity_rows:
        raise SystemExit("volume fixture returned no rows")
    for chat_id, columns in oracle_rows.items():
        counts, last = columns[:-1], columns[-1]
        in_window = any(value != "0" for value in counts)
        if in_window and window_rows.get(chat_id) != counts:
            raise SystemExit(f"window counts diverged for chat {chat_id}")
        if not in_window and chat_id in window_rows:
            raise SystemExit(f"historical-only chat {chat_id} entered the window query")
        if activity_rows.get(chat_id) != [last]:
            raise SystemExit(f"lifetime activity diverged for chat {chat_id}")
    if set(activity_rows) != set(oracle_rows):
        raise SystemExit("lifetime activity chat set diverged from the oracle")
    print("VOLUME_ORACLE_MATCH=PASS")
    print("ORACLE_COUNTS_AND_MAX")
    print(oracle)
    print("WINDOW_NO_ID_FLOOR")
    print(bounded)
    print("MAX_LAST_ACTIVITY")
    print(activity)

    window_sql = textwrap.dedent(
        f"""
        SELECT chat_id,
          SUM(CASE WHEN type = 0 AND created_at >= '{HOUR}' THEN 1 ELSE 0 END)
        FROM flarum_neonchat_messages
        WHERE deleted_by IS NULL AND created_at >= '{WEEK}'
          AND chat_id BETWEEN 1 AND 46
        GROUP BY chat_id
        """
    )
    activity_sql = textwrap.dedent(
        """
        SELECT chat_id, MAX(created_at)
        FROM flarum_neonchat_messages
        WHERE deleted_by IS NULL AND chat_id BETWEEN 1 AND 46
        GROUP BY chat_id
        """
    )
    measure("window_created_at", window_sql)
    measure("lifetime_max_created_at", activity_sql)
    print("EXPLAIN_WINDOW")
    print(sql("EXPLAIN " + window_sql))
    print("EXPLAIN_LIFETIME")
    print(sql("EXPLAIN " + activity_sql))
    print("DASHBOARD_METRICS_PLAN=PASS")


def prove_timestamp_inversion() -> None:
    """Review counterexample: an early id can be newer than later ids."""
    sql(
        textwrap.dedent(
            """
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
              (id, message, user_id, created_at, chat_id, type, deleted_by)
            VALUES
              (1, 'body-hidden', 9, '2026-10-12 17:50:00', 1, 0, NULL),
              (2, 'body-hidden', 9, '2026-09-01 00:00:00', 1, 0, NULL),
              (3, 'body-hidden', 9, '2026-09-01 00:00:00', 1, 0, NULL),
              (4, 'body-hidden', 4, '2026-10-10 10:00:00', 1, 0, NULL),
              (5, 'body-hidden', 4, '2026-10-11 10:00:00', 1, 0, NULL),
              (7, 'body-hidden', 4, '2026-10-13 00:00:00', 1, 0, 2),
              (10, 'body-hidden', 3, '2026-10-12 12:00:00', 2, 0, NULL),
              (11, 'body-hidden', 3, '2026-09-01 00:00:00', 2, 0, NULL),
              (12, 'body-hidden', 3, '2026-10-01 00:00:00', 2, 0, NULL),
              (20, 'body-hidden', 7, '2026-10-12 17:50:00', 3, 0, 1);
            """
        )
    )
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
            WHERE deleted_by IS NULL AND chat_id IN (1, 2, 3)
            GROUP BY chat_id
            ORDER BY chat_id;
            """
        )
    ).strip()
    broken_window = sql(
        textwrap.dedent(
            f"""
            SELECT SUM(CASE WHEN type = 0 AND created_at >= '{WEEK}' THEN 1 ELSE 0 END)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND chat_id = 1
              AND created_at >= '{WEEK}' AND id >= 4;
            """
        )
    ).strip()
    broken_activity = sql(
        """
        SELECT created_at FROM flarum_neonchat_messages
        WHERE chat_id = 1 AND deleted_by IS NULL
        ORDER BY id DESC LIMIT 1;
        """
    ).strip()
    corrected = sql(
        textwrap.dedent(
            f"""
            SELECT chat_id,
              SUM(CASE WHEN type = 0 AND created_at >= '{HOUR}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type = 0 AND created_at >= '{DAY}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type = 0 AND created_at >= '{WEEK}' THEN 1 ELSE 0 END),
              SUM(CASE WHEN type <> 0 AND created_at >= '{DAY}' THEN 1 ELSE 0 END),
              COUNT(DISTINCT CASE WHEN type = 0 AND created_at >= '{DAY}' THEN user_id END)
            FROM flarum_neonchat_messages
            WHERE deleted_by IS NULL AND created_at >= '{WEEK}' AND chat_id IN (1, 2, 3)
            GROUP BY chat_id
            ORDER BY chat_id;
            """
        )
    ).strip()
    corrected_activity = sql(
        """
        SELECT chat_id, MAX(created_at)
        FROM flarum_neonchat_messages
        WHERE deleted_by IS NULL AND chat_id IN (1, 2, 3)
        GROUP BY chat_id
        ORDER BY chat_id;
        """
    ).strip()
    print("INVERSION_ORACLE")
    print(oracle)
    print("BROKEN_ID_FLOOR_7D", broken_window)
    print("BROKEN_ID_ORDER_LAST", broken_activity)
    if broken_window == "3" or broken_activity == "2026-10-12 17:50:00":
        raise SystemExit("id-ordered query did not reproduce the timestamp inversion")
    oracle_counts = "\n".join(line.rsplit("\t", 1)[0] for line in oracle.splitlines())
    oracle_activity = "\n".join(
        f"{line.split(chr(9), 1)[0]}\t{line.rsplit(chr(9), 1)[1]}" for line in oracle.splitlines()
    )
    if corrected != oracle_counts or corrected_activity != oracle_activity:
        print(corrected)
        print(corrected_activity)
        raise SystemExit("timestamp query diverged from the unoptimized oracle")
    if "2026-10-12 17:50:00" not in corrected_activity.splitlines()[0]:
        raise SystemExit("chat 1 lifetime activity lost the earlier id")
    if sql(
        "SELECT COUNT(*) FROM flarum_neonchat_messages WHERE chat_id = 3 AND deleted_by IS NULL"
    ).strip() != "0":
        raise SystemExit("deleted-only chat leaked a row")
    print("TIMESTAMP_INVERSION_REGRESSION=PASS")


def measure(label: str, statement: str) -> None:
    output = sql(
        "FLUSH STATUS; SET @t = NOW(6); "
        + statement
        + "; SELECT CONCAT('ELAPSED ', TIMESTAMPDIFF(MICROSECOND, @t, NOW(6))); "
        + "SHOW SESSION STATUS LIKE 'Handler_read%';"
    )
    print(f"MEASURE {label}")
    print(output)


if __name__ == "__main__":
    main()
