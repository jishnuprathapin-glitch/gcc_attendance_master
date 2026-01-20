from __future__ import annotations

import os
import time
from dataclasses import dataclass
from typing import Optional

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


@dataclass
class LoginConfig:
    base_url: str
    login_url: str
    email: str
    password: str
    timeout: int = 20


def normalize_base_url(base_url: str) -> str:
    return base_url.strip().rstrip('/')


def build_login_url(base_url: str) -> str:
    return f"{normalize_base_url(base_url)}/HRSmart/index.php"


def create_driver(headless: bool) -> webdriver.Chrome:
    options = webdriver.ChromeOptions()
    if headless:
        options.add_argument("--headless=new")
    options.add_argument("--window-size=1600,900")
    options.add_argument("--disable-gpu")
    options.add_argument("--no-sandbox")
    return webdriver.Chrome(options=options)


def login(driver: webdriver.Chrome, config: LoginConfig) -> None:
    driver.get(config.login_url)
    wait = WebDriverWait(driver, config.timeout)

    wait.until(EC.visibility_of_element_located((By.ID, "email_id")))
    email_input = driver.find_element(By.ID, "email_id")
    password_input = driver.find_element(By.ID, "password")

    email_input.clear()
    email_input.send_keys(config.email)
    password_input.clear()
    password_input.send_keys(config.password)

    submit_btn = driver.find_element(By.ID, "submitBtn")
    submit_btn.click()

    def logged_in(driver: webdriver.Chrome) -> bool:
        current = driver.current_url
        if "index.php?err" in current:
            return True
        return "index.php" not in current

    wait.until(logged_in)
    if "index.php?err" in driver.current_url:
        raise RuntimeError("Login failed: invalid credentials or access denied.")


def wait_for_selector(driver: webdriver.Chrome, selector: str, timeout: int = 20) -> None:
    wait = WebDriverWait(driver, timeout)
    wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, selector)))


def get_bool_env(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}
