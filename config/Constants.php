<?php
namespace Config;

class Constants {
    // HTTP статусы
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;

    // Размеры и лимиты
    public const LOG_RESPONSE_LIMIT = 200;
    public const CSRF_TOKEN_LENGTH = 32;
    public const HSTS_MAX_AGE = 31536000;

    // Временные интервалы
    public const DATA_RETENTION_MONTHS = 3;
    public const CACHE_API_TTL_SECONDS = 300;

    // Процентные вычисления
    public const PERCENTAGE_MULTIPLIER = 100;
    public const PERCENTAGE_DECIMALS = 2;

    // CSS значения
    public const TABLE_WIDTH_PERCENT = '40%';
    public const TABLE_MARGIN = '20px auto';
    public const TABLE_BORDER = '1px solid #444';
    public const TABLE_PADDING = '8px 12px';
    public const TABLE_HEADER_BG = '#3a3a3a';
    public const MOBILE_BREAKPOINT = 420;
    public const MOBILE_FONT_SIZE = '13px';
    public const FORM_MARGIN_BOTTOM = '15px';

    // Значения по умолчанию
    public const DEFAULT_CAP_COUNT = 0;
    public const DEFAULT_CAP_AMOUNT = 0;
    public const FUNNEL_INDEX_OFFSET = 1;

    // Уже существующие константы
    public const NOTIFICATION_THRESHOLDS = [50, 5, 0];
    public const OVERFLOW_THRESHOLD = 105; // Порог перелива (5% и больше)
    public const RATE_LIMIT_REQUESTS = 30;
    public const RATE_LIMIT_WINDOW = 60; // секунд
}