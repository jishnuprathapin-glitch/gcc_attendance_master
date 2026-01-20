from __future__ import annotations

import argparse
import os
import sys
import time
from datetime import datetime
from pathlib import Path

from selenium.webdriver.common.by import By

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

from selenium_check import (
    LoginConfig,
    build_login_url,
    create_driver,
    get_bool_env,
    login,
    normalize_base_url,
    wait_for_selector,
)


def clamp(value: int, min_value: int, max_value: int) -> int:
    return max(min_value, min(value, max_value))


def save_viewport(driver, path: Path) -> None:
    driver.save_screenshot(str(path))


def save_full_page(driver, path: Path) -> None:
    width = driver.execute_script(
        "return Math.max(document.documentElement.clientWidth, document.body.scrollWidth, document.documentElement.scrollWidth);"
    )
    height = driver.execute_script(
        "return Math.max(document.documentElement.scrollHeight, document.body.scrollHeight, document.documentElement.scrollHeight);"
    )
    width = clamp(int(width or 1600), 1200, 3000)
    height = clamp(int(height or 900), 800, 12000)

    original_size = driver.get_window_size()
    driver.set_window_size(width, height)
    time.sleep(0.4)
    driver.execute_script("window.scrollTo(0, 0);")
    driver.save_screenshot(str(path))
    driver.set_window_size(original_size.get("width", 1600), original_size.get("height", 900))


def save_element(driver, selector: str, path: Path) -> None:
    element = driver.find_element(By.CSS_SELECTOR, selector)
    element.screenshot(str(path))


def main() -> int:
    parser = argparse.ArgumentParser(description="Capture Selenium screenshots for a page (code 347).")
    parser.add_argument("--base", required=True, help="Base URL (e.g. http://localhost)")
    parser.add_argument("--page", required=True, help="Path starting with / (e.g. /gcc_attendance_master/admin/Attendance_DeviceMapping.php)")
    parser.add_argument("--login-url", default=None, help="Override login URL (default: {base}/HRSmart/index.php)")
    parser.add_argument("--email", default=os.getenv("SELENIUM_USER_EMAIL", "test@test.com"))
    parser.add_argument("--password", default=os.getenv("SELENIUM_USER_PASSWORD", "test"))
    parser.add_argument("--wait", default="body", help="CSS selector to wait for before screenshots")
    parser.add_argument("--selector", action="append", default=[], help="CSS selector to capture element screenshot")
    parser.add_argument("--out", default="test-results/selenium", help="Output directory")
    parser.add_argument("--timeout", type=int, default=20)
    parser.add_argument("--pause", type=int, default=0, help="Seconds to keep the browser open before exit")
    args = parser.parse_args()

    base_url = normalize_base_url(args.base)
    page_path = args.page if args.page.startswith("/") else f"/{args.page}"
    page_url = f"{base_url}{page_path}"
    login_url = args.login_url or build_login_url(base_url)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    out_dir = Path(args.out) / timestamp
    out_dir.mkdir(parents=True, exist_ok=True)

    headless = get_bool_env("SELENIUM_HEADLESS", True)
    driver = create_driver(headless=headless)

    try:
        login(driver, LoginConfig(base_url=base_url, login_url=login_url, email=args.email, password=args.password, timeout=args.timeout))
        driver.get(page_url)
        wait_for_selector(driver, args.wait, timeout=args.timeout)
        time.sleep(0.6)

        save_viewport(driver, out_dir / "viewport.png")
        save_full_page(driver, out_dir / "full.png")

        for selector in args.selector:
            safe_name = selector.strip().strip("#.").replace(" ", "_").replace("/", "_")
            if not safe_name:
                safe_name = "element"
            save_element(driver, selector, out_dir / f"element_{safe_name}.png")

        if args.pause > 0:
            time.sleep(args.pause)
    finally:
        driver.quit()

    print(f"Saved screenshots to {out_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
