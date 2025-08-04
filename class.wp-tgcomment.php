<?php

/**
 * Главный класс плагина WP TGComment
 *
 * Отвечает за:
 * - Инициализацию плагина и регистрацию хуков WordPress
 * - Управление настройками и опциями плагина
 * - Создание и управление cron задачами
 * - Получение и обработка обновлений от Telegram API
 * - Управление webhook и polling режимами
 * - Создание таблиц базы данных
 * - AJAX обработчики для админ-панели
 */
class WP_TGComment {

	private static $initiated = false;
	// Константы для cron события
	const TELEGRAM_UPDATES_GET_CRON_HOOK = 'wp_tgcomment_get_updates';
	const TELEGRAM_UPDATES_INTERVAL = 'wp_tgcomment_every_two_minutes';
	private static $options = [];

	/**
	 * Инициализация плагина
	 *
	 * Проверяет, что плагин еще не инициализирован, и запускает
	 * инициализацию всех компонентов и хуков WordPress.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::$initiated ) {
			WP_TGComment_Auth::init();
			WP_TGComment_Notifier::init();
			//WP_TGComment_Login::init();
			self::init_hooks();
		}
	}

	/**
	 * Регистрация всех хуков и действий WordPress
	 *
	 * Устанавливает флаг инициализации, загружает настройки и регистрирует
	 * все необходимые хуки WordPress для работы плагина.
	 *
	 * @return void
	 */
	private static function init_hooks() {
		self::$initiated = true;
		self::$options = get_option( WP_TGCOMMENT_OPTIONS, [] );

		// Add actions and filters here
		add_action( 'init', [ self::class, 'init_plugin' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_scripts' ] );

		// Регистрируем хуки активации и деактивации

		// Регистрируем cron интервал
		add_filter( 'cron_schedules', [ self::class, 'add_cron_interval' ] );

		// Регистрируем обработчик cron события
		add_action( self::TELEGRAM_UPDATES_GET_CRON_HOOK, [ self::class, 'process_telegram_updates' ] );

		// Регистрируем обработчик cron события для комментариев
		add_action( WP_TGComment_Handler::COMMENT_PROCESSOR_CRON_HOOK, [ 'WP_TGComment_Processor', 'process_messages_to_comments' ] );
		add_action( 'pharma_paidtill_notify', [ 'WP_TGComment_Handler', 'handle_pharma_paidtill_notify' ], 20, 2 );

		// AJAX обработчики для проверки статуса
		add_action( 'wp_ajax_wp_tgcomment_check_webhook_status', [ self::class, 'ajax_check_webhook_status' ] );
		add_action( 'wp_ajax_wp_tgcomment_check_updates_cron_status', [ self::class, 'ajax_check_updates_cron_status' ] );
		add_action( 'wp_ajax_wp_tgcomment_check_processor_cron_status', [ self::class, 'ajax_check_processor_cron_status' ] );
		add_action( 'wp_ajax_wp_tgcomment_check_notifier_cron_status', [ self::class, 'ajax_check_notifier_cron_status' ] );

		// Регистрируем REST API endpoint для webhook
		add_action( 'rest_api_init', [ self::class, 'register_webhook_endpoint' ] );

		// Хук для обработки изменений настроек
		add_action( 'csf_wp_tgcomment_options_saved', [ self::class, 'handle_settings_change' ] );

		// Admin notices
		add_action( 'admin_notices', [ self::class, 'show_admin_notices' ] );

		// Подключаем скрипты для админки
		add_action( 'admin_enqueue_scripts', [ self::class, 'admin_enqueue_scripts' ] );

	}

	/**
	 * Инициализация плагина на хуке 'init'
	 *
	 * Загружает языковые файлы для интернационализации плагина.
	 *
	 * @return void
	 */
	public static function init_plugin() {
		// Plugin initialization code here
		load_plugin_textdomain( 'wp-tgcomment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Подключение скриптов и стилей фронтенда
	 *
	 * Регистрирует и подключает CSS и JavaScript файлы для фронтенда
	 * (в данный момент пустая функция).
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		// Enqueue CSS and JS files here if needed
	}

	/**
	 * Получение всех настроек плагина
	 *
	 * Возвращает массив с настройками плагина, загруженный при инициализации.
	 *
	 * @return array Массив настроек плагина
	 */
	public static function get_options() {
		return self::$options;
	}

	/**
	 * Получение токена Telegram бота
	 *
	 * Извлекает токен бота из настроек плагина для использования
	 * в запросах к Telegram Bot API.
	 *
	 * @return string Токен бота или пустая строка если не настроен
	 */
	public static function get_telegram_token() {

		return isset( self::$options['telegram_bot_token'] ) ? self::$options['telegram_bot_token'] : '';
	}

	/**
	 * Добавление кастомных интервалов для WP Cron
	 *
	 * Регистрирует специальные интервалы времени для cron задач плагина:
	 * - каждые 2 минуты для получения обновлений
	 * - каждую минуту для обработки комментариев
	 * - каждую минуту для отправки уведомлений
	 *
	 * @param array $schedules Массив существующих расписаний cron
	 * @return array Расширенный массив расписаний
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules[self::TELEGRAM_UPDATES_INTERVAL] = array(
			'interval' => 120, // 120 секунд = 2 минуты
			'display'  => __( 'Каждые 2 минуты', 'wp-tgcomment' )
		);

		$schedules[WP_TGComment_Handler::COMMENT_PROCESSOR_INTERVAL] = array(
			'interval' => 60, // 60 секунд = 1 минута
			'display'  => __( 'Каждую минуту', 'wp-tgcomment' )
		);

		$schedules[WP_TGComment_Notifier::NOTIFIER_INTERVAL] = array(
			'interval' => 60, // 60 секунд = 1 минута
			'display'  => __( 'Каждую минуту (уведомления)', 'wp-tgcomment' )
		);

		return $schedules;
	}

	/**
	 * Обработчик активации плагина
	 *
	 * Выполняется при активации плагина. Создает настройки по умолчанию,
	 * регистрирует cron задачи, создает таблицы базы данных.
	 *
	 * @return void
	 */
	public static function plugin_activation() {
		error_log( 'WP TGComment DEBUG: Начало активации плагина' );

		// Create default options
		$default_options = [
			'telegram_bot_token' => '',
			'last_update_id' => 0, // Добавляем поле для хранения ID последнего обновления
			'webhook_enabled' => '0', // По умолчанию используем polling через cron
			'drop_tables_on_deactivation' => '0', // По умолчанию не удаляем таблицы при деактивации
		];
		error_log( 'WP TGComment DEBUG: Настройки по умолчанию: ' . json_encode( $default_options, JSON_UNESCAPED_UNICODE ) );

		// Get existing options and merge with defaults to preserve user settings
		$existing_options = get_option( WP_TGCOMMENT_OPTIONS, [] );
		error_log( 'WP TGComment DEBUG: Существующие настройки: ' . json_encode( $existing_options, JSON_UNESCAPED_UNICODE ) );

		$merged_options = wp_parse_args( $existing_options, $default_options );
		error_log( 'WP TGComment DEBUG: Объединенные настройки: ' . json_encode( $merged_options, JSON_UNESCAPED_UNICODE ) );

		// Update options with merged values
		$update_result = update_option( WP_TGCOMMENT_OPTIONS, $merged_options );
		error_log( 'WP TGComment DEBUG: Результат обновления настроек: ' . ( $update_result ? 'успешно' : 'ошибка' ) );

		// Update the class options property for consistency
		self::$options = $merged_options;

		// Регистрируем cron событие (только если webhook отключен)
		$options = $merged_options;
		$webhook_enabled = $options['webhook_enabled'] == '1';
		$updates_cron_scheduled = wp_next_scheduled( self::TELEGRAM_UPDATES_GET_CRON_HOOK );

		error_log( 'WP TGComment DEBUG: webhook_enabled = ' . $options['webhook_enabled'] . ', updates_cron_scheduled = ' . ( $updates_cron_scheduled ? date( 'Y-m-d H:i:s', $updates_cron_scheduled ) : 'нет' ) );

		if ( $options['webhook_enabled'] =='0' && ! $updates_cron_scheduled ) {
			$cron_result = wp_schedule_event( time(), self::TELEGRAM_UPDATES_INTERVAL, self::TELEGRAM_UPDATES_GET_CRON_HOOK );
			error_log( 'WP TGComment DEBUG: Регистрация cron события для получения обновлений: ' . ( $cron_result !== false ? 'успешно' : 'ошибка' ) );
		} else {
			error_log( 'WP TGComment DEBUG: Cron для получения обновлений не регистрируем (webhook включен или уже запланирован)' );
		}

		// Регистрируем cron событие для обработки комментариев
		$processor_cron_scheduled = wp_next_scheduled( WP_TGComment_Handler::COMMENT_PROCESSOR_CRON_HOOK );
		error_log( 'WP TGComment DEBUG: processor_cron_scheduled = ' . ( $processor_cron_scheduled ? date( 'Y-m-d H:i:s', $processor_cron_scheduled ) : 'нет' ) );

		if ( ! $processor_cron_scheduled ) {
			$cron_result = wp_schedule_event( time(), WP_TGComment_Handler::COMMENT_PROCESSOR_INTERVAL, WP_TGComment_Handler::COMMENT_PROCESSOR_CRON_HOOK );
			error_log( 'WP TGComment DEBUG: Регистрация cron события для обработки комментариев: ' . ( $cron_result !== false ? 'успешно' : 'ошибка' ) );
		} else {
			error_log( 'WP TGComment DEBUG: Cron для обработки комментариев уже запланирован' );
		}

		// Регистрируем cron событие для отправки уведомлений
		$notifier_cron_scheduled = wp_next_scheduled( WP_TGComment_Notifier::NOTIFIER_CRON_HOOK );
		error_log( 'WP TGComment DEBUG: notifier_cron_scheduled = ' . ( $notifier_cron_scheduled ? date( 'Y-m-d H:i:s', $notifier_cron_scheduled ) : 'нет' ) );

		if ( ! $notifier_cron_scheduled ) {
			$cron_result = wp_schedule_event( time(), WP_TGComment_Notifier::NOTIFIER_INTERVAL, WP_TGComment_Notifier::NOTIFIER_CRON_HOOK );
			error_log( 'WP TGComment DEBUG: Регистрация cron события для отправки уведомлений: ' . ( $cron_result !== false ? 'успешно' : 'ошибка' ) );
		} else {
			error_log( 'WP TGComment DEBUG: Cron для отправки уведомлений уже запланирован' );
		}

		// Создаем таблицы
		error_log( 'WP TGComment DEBUG: Начинаем создание таблиц' );

		try {
			self::create_incoming_messages_table();
			error_log( 'WP TGComment DEBUG: Таблица входящих сообщений создана успешно' );
		} catch ( Exception $e ) {
			error_log( 'WP TGComment DEBUG: Ошибка создания таблицы входящих сообщений: ' . $e->getMessage() );
		}

		try {
			WP_TGComment_Notifier::create_outgoing_notifications_table();
			error_log( 'WP TGComment DEBUG: Таблица исходящих уведомлений создана успешно' );
		} catch ( Exception $e ) {
			error_log( 'WP TGComment DEBUG: Ошибка создания таблицы исходящих уведомлений: ' . $e->getMessage() );
		}

		// Проверяем финальное состояние cron событий
		$final_updates_cron = wp_next_scheduled( self::TELEGRAM_UPDATES_GET_CRON_HOOK );
		$final_processor_cron = wp_next_scheduled( WP_TGComment_Handler::COMMENT_PROCESSOR_CRON_HOOK );
		$final_notifier_cron = wp_next_scheduled( WP_TGComment_Notifier::NOTIFIER_CRON_HOOK );

		error_log( 'WP TGComment DEBUG: Финальное состояние cron событий:' );
		error_log( 'WP TGComment DEBUG: - Updates: ' . ( $final_updates_cron ? date( 'Y-m-d H:i:s', $final_updates_cron ) : 'не запланирован' ) );
		error_log( 'WP TGComment DEBUG: - Processor: ' . ( $final_processor_cron ? date( 'Y-m-d H:i:s', $final_processor_cron ) : 'не запланирован' ) );
		error_log( 'WP TGComment DEBUG: - Notifier: ' . ( $final_notifier_cron ? date( 'Y-m-d H:i:s', $final_notifier_cron ) : 'не запланирован' ) );

		// Логируем активацию
		error_log( 'WP TGComment: Плагин активирован, cron события зарегистрированы, таблицы созданы' );
		error_log( 'WP TGComment DEBUG: Активация плагина завершена успешно' );
	}

	/**
	 * Обработчик деактивации плагина
	 *
	 * Выполняется при деактивации плагина. Удаляет webhook, очищает
	 * cron задачи, опционально удаляет таблицы.
	 *
	 * @return void
	 */
	public static function plugin_deactivation() {
		// Удаляем webhook если он был активен
		$options = self::get_options();
		$webhook_enabled = isset( $options['webhook_enabled'] ) ? $options['webhook_enabled']=='1' : false;

		if ( $webhook_enabled ) {
			$webhook_result = self::delete_webhook();
			if ( $webhook_result['success'] ) {
				error_log( 'WP TGComment: Webhook удален при деактивации плагина' );
			} else {
				error_log( 'WP TGComment: Ошибка удаления webhook при деактивации: ' . $webhook_result['message'] );
			}
		}

		// Удаляем cron события
		wp_clear_scheduled_hook( self::TELEGRAM_UPDATES_GET_CRON_HOOK );
		wp_clear_scheduled_hook( WP_TGComment_Handler::COMMENT_PROCESSOR_CRON_HOOK );
		wp_clear_scheduled_hook( WP_TGComment_Notifier::NOTIFIER_CRON_HOOK );

		// Проверяем опцию удаления таблиц
		$drop_tables = isset( $options['drop_tables_on_deactivation'] ) ? $options['drop_tables_on_deactivation'] : false;

		if ( $drop_tables ) {
			self::drop_plugin_tables();
		}

		// Логируем деактивацию
		$tables_status = $drop_tables ? ', таблицы удалены' : '';
		error_log( 'WP TGComment: Плагин деактивирован, webhook и cron события удалены' . $tables_status );
	}

	/**
	 * Получение обновлений от Telegram API (Polling)
	 *
	 * Выполняет запрос к Telegram API для получения новых обновлений
	 * с использованием long polling. Сохраняет offset последнего обновления.
	 *
	 * @return array|false Массив обновлений или false при ошибке
	 */
	public static function get_telegram_updates() {
		$token = self::get_telegram_token();

		if ( empty( $token ) ) {
			error_log( 'WP TGComment: Telegram bot token не настроен' );
			return false;
		}

		$options = self::get_options();
		$offset = isset( $options['last_update_id'] ) ? intval( $options['last_update_id'] ) + 1 : 0;

		error_log( "WP TGComment: Запрашиваем updates с offset {$offset}" );

		$url = "https://api.telegram.org/bot{$token}/getUpdates";
		$params = array(
			'offset' => $offset,
			'limit' => 100,
			'timeout' => 10
		);

		$request_url = $url . '?' . http_build_query( $params );

		// Выполняем запрос к Telegram API
		$response = wp_remote_get( $request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json'
			)
		) );

		if ( is_wp_error( $response ) ) {
			error_log( 'WP TGComment: Ошибка запроса к Telegram API: ' . $response->get_error_message() );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['ok'] ) || ! $data['ok'] ) {
			error_log( 'WP TGComment: Неверный ответ от Telegram API: ' . $body );

			// Проверяем на конфликт webhook/polling (error_code 409)
			if ( isset( $data['error_code'] ) && $data['error_code'] == 409 ) {
				// Удаляем cron событие опроса, так как активен webhook
				wp_clear_scheduled_hook( self::TELEGRAM_UPDATES_GET_CRON_HOOK );
				error_log( 'WP TGComment: Cron опроса отключен из-за конфликта с webhook (error 409)' );
			}

			return false;
		}

		$updates_count = is_array( $data['result'] ) ? count( $data['result'] ) : 0;
		error_log( "WP TGComment: Получено {$updates_count} updates" );

		if ( $updates_count > 0 ) {
			error_log( "WP TGComment: Updates: " . json_encode( $data['result'], JSON_UNESCAPED_UNICODE ) );
		}

		return $data['result'];
	}

	/**
	 * Обработка всех полученных обновлений от Telegram
	 *
	 * Основная функция cron задачи для получения и обработки обновлений.
	 * Использует блокировку для предотвращения одновременного выполнения.
	 *
	 * @return void
	 */
	public static function process_telegram_updates() {
		// Проверяем, не выполняется ли уже процесс получения обновлений
		$lock_key = 'wp_tgcomment_processing_lock';
		$lock_value = get_transient( $lock_key );
		if ( $lock_value ) {
			return;
		}
		set_transient( $lock_key, time(), 120 );
		$updates = self::get_telegram_updates();
		error_log(json_encode($updates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		delete_transient( $lock_key );

		if ( ! $updates || empty( $updates ) ) {
			return;
		}

		$options = self::get_options();
		$last_update_id = isset( $options['last_update_id'] ) ? intval( $options['last_update_id'] ) : 0;

		foreach ( $updates as $update ) {
			if ( isset( $update['update_id'] ) ) {
				$last_update_id = max( $last_update_id, $update['update_id'] );
				// Обрабатываем каждое обновление
				self::process_single_update( $update );
			}
		}

		// Сохраняем ID последнего обновления
		$options['last_update_id'] = $last_update_id;
		update_option( WP_TGCOMMENT_OPTIONS, $options );
	}

	/**
	 * Обработка одного обновления от Telegram
	 *
	 * Анализирует тип обновления (сообщение, callback query и т.д.)
	 * и направляет на соответствующий обработчик.
	 *
	 * @param array $update Данные обновления от Telegram API
	 * @return void
	 */
	public static function process_single_update( $update ) {
		// Обрабатываем сообщения
		if ( isset( $update['message'] ) ) {
			$message = $update['message'];
			error_log( "WP TGComment: Получено сообщение от: " . ($message['from']['username'] ?? $message['from']['id']) );
			WP_TGComment_Handler::process_message( $message );
		}
		// Обрабатываем отредактированные сообщения
		if ( isset( $update['edited_message'] ) ) {
			$edited_message = $update['edited_message'];
			error_log( "WP TGComment: Получено отредактированное сообщение: " . json_encode( $edited_message, JSON_UNESCAPED_UNICODE ) );
			// Здесь можно добавить обработку отредактированных сообщений
		}
		// Обрабатываем callback queries (нажатия на кнопки)
		if ( isset( $update['callback_query'] ) ) {
			$callback_query = $update['callback_query'];
			error_log( "WP TGComment: Получен callback query от: " . ($callback_query['from']['username'] ?? $callback_query['from']['id']) );
			WP_TGComment_Handler::process_callback_query( $callback_query );
		}

	}

	/**
	 * Получение информации о статусе cron событий
	 *
	 * Возвращает детальную информацию о состоянии cron задачи
	 * получения обновлений от Telegram.
	 *
	 * @return array Массив с информацией о статусе cron
	 */
	public static function get_cron_status() {
		$next_scheduled = wp_next_scheduled( self::TELEGRAM_UPDATES_GET_CRON_HOOK );
		$options = self::get_options();

		return array(
			'is_scheduled' => ! ! $next_scheduled,
			'next_run' => $next_scheduled ? date( 'Y-m-d H:i:s', $next_scheduled ) : 'Не запланировано',
			'next_run_timestamp' => $next_scheduled,
			'last_update_id' => isset( $options['last_update_id'] ) ? $options['last_update_id'] : 0,
			'bot_token_configured' => ! empty( self::get_telegram_token() )
		);
	}

	/**
	 * Ручной запуск получения обновлений
	 *
	 * Позволяет вручную запустить процесс получения обновлений
	 * для тестирования или устранения неполадок.
	 *
	 * @return void
	 */
	public static function manual_get_updates() {
		error_log( 'WP TGComment: Ручной запуск получения обновлений' );
		self::process_telegram_updates();
	}

	/**
	 * Перезапуск cron события получения обновлений
	 *
	 * Удаляет существующую cron задачу и создает новую.
	 * Используется для устранения проблем с расписанием.
	 *
	 * @return bool true при успехе, false при ошибке
	 */
	public static function reschedule_cron() {
		// Удаляем существующее событие
		wp_clear_scheduled_hook( self::TELEGRAM_UPDATES_GET_CRON_HOOK );

		// Создаем новое
		if ( ! wp_next_scheduled( self::TELEGRAM_UPDATES_GET_CRON_HOOK ) ) {
			wp_schedule_event( time(), self::TELEGRAM_UPDATES_INTERVAL, self::TELEGRAM_UPDATES_GET_CRON_HOOK );
			error_log( 'WP TGComment: Cron событие перезапущено' );
			return true;
		}

		return false;
	}

	/**
	 * Принудительная очистка блокировки обработки
	 *
	 * Удаляет transient блокировку процесса получения обновлений.
	 * Используется при зависании процесса.
	 *
	 * @return bool true всегда
	 */
	public static function clear_processing_lock() {
		$lock_key = 'wp_tgcomment_processing_lock';
		delete_transient( $lock_key );
		error_log( 'WP TGComment: Блокировка процесса принудительно очищена' );
		return true;
	}

	/**
	 * Тестирование соединения с Telegram API
	 *
	 * Проверяет валидность токена бота и доступность API
	 * с помощью метода getMe.
	 *
	 * @return array Результат тестирования с ключами success, message, bot_info
	 */
	public static function test_telegram_connection() {
		$token = self::get_telegram_token();

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => 'Telegram bot token не настроен'
			);
		}

		$url = "https://api.telegram.org/bot{$token}/getMe";

		$response = wp_remote_get( $url, array(
			'timeout' => 10
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => 'Ошибка соединения: ' . $response->get_error_message()
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['ok'] ) || ! $data['ok'] ) {
			return array(
				'success' => false,
				'message' => 'Неверный ответ от API: ' . $body
			);
		}

		$bot_info = $data['result'];

		return array(
			'success' => true,
			'message' => 'Соединение успешно',
			'bot_info' => $bot_info
		);
	}

	/**
	 * Подключение скриптов для админ-панели
	 *
	 * Регистрирует JavaScript и CSS для страницы настроек плагина.
	 * Подключает AJAX обработчики и локализацию.
	 *
	 * @param string $hook Идентификатор текущей страницы админки
	 * @return void
	 */
	public static function admin_enqueue_scripts( $hook ) {
		// Подключаем только на странице настроек плагина
		if ( $hook !== 'settings_page_wp-tgcomment-settings' ) {
			return;
		}

		// Подключаем встроенный JavaScript
		wp_add_inline_script( 'wp-util', self::get_admin_javascript() );

		// Локализуем AJAX URL
		wp_localize_script( 'wp-util', 'wpTgCommentAjax', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'wp_tgcomment_nonce' )
		) );
	}

	/**
	 * Генерация JavaScript кода для админ-панели
	 *
	 * Возвращает встроенный JavaScript для кнопок проверки статуса
	 * системы в админ-панели.
	 *
	 * @return string JavaScript код
	 */
	public static function get_admin_javascript() {
		return "
		function wpTgCommentCheckWebhookStatus() {
			var resultDiv = document.getElementById('webhook-status-result');
			resultDiv.innerHTML = '<span style=\"color: #0073aa;\">Проверка...</span>';

			fetch(wpTgCommentAjax.ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: 'action=wp_tgcomment_check_webhook_status&nonce=' + wpTgCommentAjax.nonce
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					resultDiv.innerHTML = '<span style=\"color: #46b450;\">✅ ' + data.data.message + '</span>';
				} else {
					resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ ' + data.data.message + '</span>';
				}
			})
			.catch(error => {
				resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ Ошибка</span>';
			});
		}

		function wpTgCommentCheckUpdatesCronStatus() {
			var resultDiv = document.getElementById('updates-cron-status-result');
			resultDiv.innerHTML = '<span style=\"color: #0073aa;\">Проверка...</span>';

			fetch(wpTgCommentAjax.ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: 'action=wp_tgcomment_check_updates_cron_status&nonce=' + wpTgCommentAjax.nonce
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					resultDiv.innerHTML = '<span style=\"color: #46b450;\">✅ ' + data.data.message + '</span>';
				} else {
					resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ ' + data.data.message + '</span>';
				}
			})
			.catch(error => {
				resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ Ошибка</span>';
			});
		}

		function wpTgCommentCheckProcessorCronStatus() {
			var resultDiv = document.getElementById('processor-cron-status-result');
			resultDiv.innerHTML = '<span style=\"color: #0073aa;\">Проверка...</span>';

			fetch(wpTgCommentAjax.ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: 'action=wp_tgcomment_check_processor_cron_status&nonce=' + wpTgCommentAjax.nonce
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					resultDiv.innerHTML = '<span style=\"color: #46b450;\">✅ ' + data.data.message + '</span>';
				} else {
					resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ ' + data.data.message + '</span>';
				}
			})
			.catch(error => {
				resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ Ошибка</span>';
			});
		}

		function wpTgCommentCheckNotifierCronStatus() {
			var resultDiv = document.getElementById('notifier-cron-status-result');
			resultDiv.innerHTML = '<span style=\"color: #0073aa;\">Проверка...</span>';

			fetch(wpTgCommentAjax.ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: 'action=wp_tgcomment_check_notifier_cron_status&nonce=' + wpTgCommentAjax.nonce
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					resultDiv.innerHTML = '<span style=\"color: #46b450;\">✅ ' + data.data.message + '</span>';
				} else {
					resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ ' + data.data.message + '</span>';
				}
			})
			.catch(error => {
				resultDiv.innerHTML = '<span style=\"color: #dc3232;\">❌ Ошибка</span>';
			});
		}
		";
	}

	/**
	 * AJAX обработчик проверки статуса webhook
	 *
	 * Проверяет права доступа и возвращает информацию о том,
	 * установлен ли webhook в Telegram.
	 *
	 * @return void Завершается wp_send_json_success или wp_send_json_error
	 */
	public static function ajax_check_webhook_status() {
		// Проверяем nonce
		if ( ! wp_verify_nonce( $_POST['nonce'], 'wp_tgcomment_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		// Проверяем права доступа
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав доступа' ) );
		}

		$result = self::get_webhook_info();

		if ( $result['is_set'] ) {
			wp_send_json_success( array( 'message' => 'Установлен' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Не настроен' ) );
		}
	}

	/**
	 * AJAX обработчик проверки статуса cron получения обновлений
	 *
	 * Проверяет, запланирована ли cron задача получения обновлений
	 * и возвращает время следующего запуска.
	 *
	 * @return void Завершается wp_send_json_success или wp_send_json_error
	 */
	public static function ajax_check_updates_cron_status() {
		// Проверяем nonce
		if ( ! wp_verify_nonce( $_POST['nonce'], 'wp_tgcomment_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		// Проверяем права доступа
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав доступа' ) );
		}

		$next_scheduled = wp_next_scheduled( self::TELEGRAM_UPDATES_GET_CRON_HOOK );

		if ( $next_scheduled ) {
			$next_run = date( 'H:i:s', $next_scheduled );
			wp_send_json_success( array( 'message' => "Активен (следующий запуск: {$next_run})" ) );
		} else {
			wp_send_json_error( array( 'message' => 'Не запланирован' ) );
		}
	}

	/**
	 * AJAX обработчик проверки статуса cron обработки сообщений
	 *
	 * Проверяет, запланирована ли cron задача обработки очереди
	 * входящих сообщений.
	 *
	 * @return void Завершается wp_send_json_success или wp_send_json_error
	 */
	public static function ajax_check_processor_cron_status() {
		// Проверяем nonce
		if ( ! wp_verify_nonce( $_POST['nonce'], 'wp_tgcomment_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		// Проверяем права доступа
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав доступа' ) );
		}

		$next_scheduled = wp_next_scheduled( WP_TGComment_Handler::COMMENT_PROCESSOR_CRON_HOOK );

		if ( $next_scheduled ) {
			$next_run = date( 'H:i:s', $next_scheduled );
			wp_send_json_success( array( 'message' => "Активен (следующий запуск: {$next_run})" ) );
		} else {
			wp_send_json_error( array( 'message' => 'Не запланирован' ) );
		}
	}

	/**
	 * AJAX обработчик проверки статуса cron отправки уведомлений
	 *
	 * Проверяет, запланирована ли cron задача отправки уведомлений
	 * пользователям в Telegram.
	 *
	 * @return void Завершается wp_send_json_success или wp_send_json_error
	 */
	public static function ajax_check_notifier_cron_status() {
		// Проверяем nonce
		if ( ! wp_verify_nonce( $_POST['nonce'], 'wp_tgcomment_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		// Проверяем права доступа
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав доступа' ) );
		}

		$next_scheduled = wp_next_scheduled( WP_TGComment_Notifier::NOTIFIER_CRON_HOOK );

		if ( $next_scheduled ) {
			$next_run = date( 'H:i:s', $next_scheduled );
			wp_send_json_success( array( 'message' => "Активен (следующий запуск: {$next_run})" ) );
		} else {
			wp_send_json_error( array( 'message' => 'Не запланирован' ) );
		}
	}













	/**
	 * Регистрация REST API endpoint для webhook
	 *
	 * Создает публичный endpoint для приема webhook запросов от Telegram.
	 * URL: /wp-json/wp-tgcomment/v1/webhook/
	 *
	 * @return void
	 */
	public static function register_webhook_endpoint() {
		register_rest_route( 'wp-tgcomment/v1', '/webhook', array(
			'methods' => 'POST',
			'callback' => [ self::class, 'handle_webhook_request' ],
			'permission_callback' => '__return_true' // Разрешаем всем
		) );
	}

	/**
	 * Обработчик webhook запросов от Telegram
	 *
	 * Принимает POST запросы от Telegram, валидирует JSON
	 * и передает обновления в обработчик.
	 *
	 * @return void Завершается wp_die с кодом ответа
	 */
	public static function handle_webhook_request() {
		// Получаем сырые данные
		$input = file_get_contents( 'php://input' );

		if ( empty( $input ) ) {
			error_log( 'WP TGComment Webhook: Пустой запрос' );
			wp_die( 'Invalid request', 'Bad Request', array( 'response' => 400 ) );
		}

		// Декодируем JSON
		$update = json_decode( $input, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( 'WP TGComment Webhook: Ошибка декодирования JSON: ' . json_last_error_msg() );
			wp_die( 'Invalid JSON', 'Bad Request', array( 'response' => 400 ) );
		}

		// Логируем получение обновления
		error_log( 'WP TGComment Webhook: Получено обновление: ' . json_encode( $update, JSON_UNESCAPED_UNICODE ) );

		// Обрабатываем обновление через существующий метод
		self::process_single_update( $update );

		// Возвращаем успешный ответ
		wp_die( 'OK', 'OK', array( 'response' => 200 ) );
	}



	/**
	 * Отображение уведомлений в админ-панели
	 *
	 * Показывает предупреждения администратору если не настроен
	 * ни один метод получения обновлений от Telegram.
	 *
	 * @return void
	 */
	public static function show_admin_notices() {
		// Показываем уведомления только на страницах администратора и только для пользователей с правами
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Показываем уведомления только на страницах, связанных с плагином
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Проверяем только на главной странице админки, странице плагинов и странице настроек плагина
		$allowed_screens = array( 'dashboard', 'plugins', 'settings_page_wp-tgcomment-settings' );
		if ( ! in_array( $screen->id, $allowed_screens ) ) {
			return;
		}

		$options = self::get_options();
		$webhook_enabled = isset( $options['webhook_enabled'] ) ? $options['webhook_enabled']=='1' : false;
		$cron_scheduled = wp_next_scheduled( self::TELEGRAM_UPDATES_GET_CRON_HOOK );
		$bot_token_configured = ! empty( self::get_telegram_token() );

		// Показываем уведомление только если токен настроен, но нет активного метода получения обновлений
		if ( $bot_token_configured && ! $webhook_enabled && ! $cron_scheduled ) {
			echo '<div class="notice notice-warning is-dismissible">
				<p><strong>WP TGComment:</strong> ' . __( 'Не активен ни один метод получения обновлений от Telegram. Включите Webhook в настройках плагина или проверьте работу Cron.', 'wp-tgcomment' ) . '
				<a href="' . admin_url( 'options-general.php?page=wp-tgcomment-settings' ) . '">' . __( 'Перейти к настройкам', 'wp-tgcomment' ) . '</a></p>
			</div>';
		}
	}

	/**
	 * Получение статуса методов получения обновлений
	 *
	 * Анализирует какой метод получения обновлений активен:
	 * webhook или polling через cron.
	 *
	 * @return array Массив с информацией об активных методах
	 */
	public static function get_update_method_status() {
		$options = self::get_options();
		$webhook_enabled = isset( $options['webhook_enabled'] ) ? $options['webhook_enabled']=='1' : false;
		$cron_scheduled = wp_next_scheduled( self::TELEGRAM_UPDATES_GET_CRON_HOOK );

		return array(
			'webhook_enabled' => $webhook_enabled,
			'cron_scheduled' => ! ! $cron_scheduled,
			'has_active_method' => $webhook_enabled || $cron_scheduled
		);
	}


	/**
	 * Создание таблицы входящих сообщений
	 *
	 * Создает таблицу wp_tgcomments_incoming для хранения
	 * входящих сообщений от Telegram в очереди обработки.
	 *
	 * @return void
	 */
	public static function create_incoming_messages_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_TGComment_Handler::TABLE_INCOMING;

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) NOT NULL DEFAULT 0,
			telegram_user_id bigint(20) NOT NULL,
			chat_id bigint(20) NOT NULL,
			tg_message_id bigint(20) NOT NULL,
			post_id bigint(20) NOT NULL DEFAULT 0,
			telegram_message_json longtext NOT NULL,
			media_group_id varchar(50) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			last_processing_attempt datetime DEFAULT NULL,
			retry_count int(11) DEFAULT 0,
			is_deleted tinyint(1) DEFAULT 0,
			PRIMARY KEY (id),
			KEY telegram_user_id (telegram_user_id),
			KEY wp_user_id (wp_user_id),
			KEY chat_id (chat_id),
			KEY tg_message_id (tg_message_id),
			KEY post_id (post_id),
			KEY media_group_id (media_group_id),
			KEY created_at (created_at),
			KEY last_processing_attempt (last_processing_attempt),
			KEY retry_count (retry_count),
			KEY is_deleted (is_deleted),
			UNIQUE KEY unique_message (chat_id, tg_message_id)
		) {$wpdb->get_charset_collate()};";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		error_log( 'WP TGComment: Таблица входящих сообщений создана или обновлена' );
	}



	/**
	 * Получение информации о webhook от Telegram API
	 *
	 * Запрашивает у Telegram API информацию о текущем
	 * установленном webhook.
	 *
	 * @return array Массив с информацией о webhook или ошибке
	 */
	public static function get_webhook_info() {
		$token = self::get_telegram_token();

		if ( empty( $token ) ) {
			return array(
				'is_set' => false,
				'error' => 'Токен бота не настроен'
			);
		}

		$url = "https://api.telegram.org/bot{$token}/getWebhookInfo";

		$response = wp_remote_get( $url, array(
			'timeout' => 10
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'is_set' => false,
				'error' => 'Ошибка соединения: ' . $response->get_error_message()
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['ok'] ) || ! $data['ok'] ) {
			return array(
				'is_set' => false,
				'error' => 'Неверный ответ от API: ' . $body
			);
		}

		$webhook_info = $data['result'];

		return array(
			'is_set' => ! empty( $webhook_info['url'] ),
			'url' => $webhook_info['url'] ?? '',
			'has_custom_certificate' => $webhook_info['has_custom_certificate'] ?? false,
			'pending_update_count' => $webhook_info['pending_update_count'] ?? 0,
			'last_error_date' => $webhook_info['last_error_date'] ?? 0,
			'last_error_message' => $webhook_info['last_error_message'] ?? '',
			'max_connections' => $webhook_info['max_connections'] ?? 0,
			'allowed_updates' => $webhook_info['allowed_updates'] ?? array()
		);
	}

	/**
	 * Установка webhook в Telegram
	 *
	 * Отправляет запрос к Telegram API для установки webhook
	 * на endpoint плагина. Требует HTTPS.
	 *
	 * @return array Результат операции с ключами success, message, url
	 */
	public static function set_webhook() {
		$token = self::get_telegram_token();

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => 'Токен бота не настроен'
			);
		}

		// Проверяем HTTPS
		if ( ! is_ssl() ) {
			return array(
				'success' => false,
				'message' => 'Webhook требует HTTPS соединения'
			);
		}

		$webhook_url = site_url( '/wp-json/wp-tgcomment/v1/webhook/' );
		$url = "https://api.telegram.org/bot{$token}/setWebhook";

		$data = array(
			'url' => $webhook_url,
			'allowed_updates' => json_encode( array( 'message', 'edited_message', 'callback_query' ) ),
			'drop_pending_updates' => true
		);

		$response = wp_remote_post( $url, array(
			'body' => $data,
			'timeout' => 15
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => 'Ошибка соединения: ' . $response->get_error_message()
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! $result || ! isset( $result['ok'] ) || ! $result['ok'] ) {
			$error_message = isset( $result['description'] ) ? $result['description'] : 'Неизвестная ошибка';
			return array(
				'success' => false,
				'message' => 'Ошибка API: ' . $error_message
			);
		}

		error_log( 'WP TGComment: Webhook успешно установлен на ' . $webhook_url );
		return array(
			'success' => true,
			'message' => 'Webhook успешно установлен',
			'url' => $webhook_url
		);
	}

	/**
	 * Удаление webhook из Telegram
	 *
	 * Отправляет запрос к Telegram API для удаления
	 * установленного webhook.
	 *
	 * @return array Результат операции с ключами success, message
	 */
	public static function delete_webhook() {
		$token = self::get_telegram_token();

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => 'Токен бота не настроен'
			);
		}

		$url = "https://api.telegram.org/bot{$token}/deleteWebhook";

		$data = array(
			'drop_pending_updates' => true
		);

		$response = wp_remote_post( $url, array(
			'body' => $data,
			'timeout' => 15
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => 'Ошибка соединения: ' . $response->get_error_message()
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! $result || ! isset( $result['ok'] ) || ! $result['ok'] ) {
			$error_message = isset( $result['description'] ) ? $result['description'] : 'Неизвестная ошибка';
			return array(
				'success' => false,
				'message' => 'Ошибка API: ' . $error_message
			);
		}

		error_log( 'WP TGComment: Webhook удален' );
		return array(
			'success' => true,
			'message' => 'Webhook успешно удален'
		);
	}

	/**
	 * Удаление таблиц плагина при деактивации
	 *
	 * Полностью удаляет все таблицы базы данных плагина.
	 * Используется только если включена соответствующая опция.
	 *
	 * @return void
	 */
	private static function drop_plugin_tables() {
		global $wpdb;

		// Удаляем таблицы плагина
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}" . WP_TGComment_Handler::TABLE_INCOMING . "`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}" . WP_TGComment_Notifier::TABLE_OUTGOING . "`" );

		error_log( 'WP TGComment: Таблицы плагина удалены' );
	}



}
