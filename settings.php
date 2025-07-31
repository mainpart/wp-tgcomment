<?php
/**
 * Настройки админ-панели плагина WP TGComment
 * 
 * Использует фреймворк Codestar Framework (CSF) для создания страницы настроек.
 * Включает:
 * - Поля для настройки Telegram бота
 * - Переключатели режимов работы (Webhook/Polling)
 * - Валидацию токена бота и настройку webhook
 * - Кнопки проверки статуса системы
 * - Обработчики сохранения настроек
 */

// Control core classes for avoid errors
if ( class_exists( 'CSF' ) ) {

	//
	// Set a unique slug-like ID
	$prefix = WP_TGCOMMENT_OPTIONS;

	//
	// Create options
	CSF::createOptions( $prefix, array(
		'menu_title'        => 'WP TGComment',
		'menu_slug'         => 'wp-tgcomment-settings',
		'menu_type'         => 'submenu',
		'menu_parent'       => 'options-general.php',
		'menu_capability'   => 'manage_options',
		'show_bar_menu'     => false,
		'framework_title'   => 'Настройки плагина WP TGComment',
		'theme'             => 'light',
		'nav'               => 'inline',
		'show_form_warning' => false,
	) );

	CSF::createSection( $prefix, array(
		'id'    => 'settings',
		'title' => 'Settings'
	) );

	//
	// Create a sub-tab
	CSF::createSection( $prefix, array(
		'id'     => 'settings-telegram',
		'parent' => 'settings',
		'title'  => 'Telegram Bot',
		'fields' => array(

			// Telegram Bot Token field
			array(
				'id'       => 'telegram_bot_token',
				'type'     => 'text',
				'title'    => 'Telegram Bot Token',
				'subtitle' => 'Введите токен вашего Telegram бота',
				'desc'     => 'Получить токен можно у @BotFather в Telegram',
				'help'     => 'Формат: 123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ',
				'default'  => '',
				'validate' => 'csf_validate_telegram_token',
			),

			// Webhook Settings
			array(
				'id'       => 'webhook_enabled',
				'type'     => 'switcher',
				'title'    => 'Использовать Webhook',
				'subtitle' => 'Переключение между Polling (cron) и Webhook режимами',
				'desc'     => 'Webhook обеспечивает мгновенную доставку обновлений, но требует HTTPS. При выключении используется Polling через cron.',
				'default'  => false,
				'dependency' => array( 'telegram_bot_token', '!=', '' ),
				'validate' => 'csf_validate_webhook_setup',
			),

			// File Sending Method
			array(
				'id'       => 'send_files_direct',
				'type'     => 'switcher',
				'title'    => 'Отправлять файлы напрямую',
				'subtitle' => 'Переключение между отправкой ссылок и загрузкой файлов',
				'desc'     => 'Включено: файлы загружаются напрямую в Telegram (рекомендуется). Выключено: отправляются ссылки на файлы (может не работать).',
				'default'  => '0',
				'dependency' => array( 'telegram_bot_token', '!=', '' ),
			),

			// Drop Tables on Deactivation
			array(
				'id'       => 'drop_tables_on_deactivation',
				'type'     => 'switcher',
				'title'    => 'Удалять таблицы при деактивации',
				'subtitle' => 'Полное удаление данных плагина при деактивации',
				'desc'     => 'Внимание! При включении этой опции все данные плагина (входящие сообщения, исходящие уведомления) будут безвозвратно удалены при деактивации плагина.',
				'default'  => false,
			),


			// Status Check Buttons
			array(
				'id'       => 'status_check_buttons',
				'type'     => 'content',
				'title'    => 'Проверка статуса системы',
				'subtitle' => 'Кнопки для проверки работы различных компонентов',
				'content'  => wp_tgcomment_get_status_check_buttons_html(),
				'dependency' => array( 'telegram_bot_token', '!=', '' ),
			),

		)
	) );

	/**
	 * Валидация токена Telegram бота
	 * 
	 * Проверяет формат токена и его валидность через запрос к Telegram API.
	 * Использует метод getMe для проверки доступности бота.
	 * 
	 * @param string $value Токен бота для проверки
	 * @return string|void Сообщение об ошибке или void при успехе
	 */
	function csf_validate_telegram_token( $value ) {
		// Skip validation if empty
		if ( empty( $value ) ) {
			return;
		}

		// Basic validation for Telegram bot token format
		if ( ! preg_match( '/^\d+:[A-Za-z0-9_-]+$/', $value ) ) {
			return 'Неверный формат токена Telegram бота';
		}

		// Validate token by calling Telegram API
		$api_url = 'https://api.telegram.org/bot' . $value . '/getMe';

		$response = wp_remote_get( $api_url, array(
			'timeout' => 10,
			'sslverify' => true,
		) );

		// Get response data
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $response_body, true );

		// Check all failure conditions in one if statement
		if ( is_wp_error( $response ) ||
			 $response_code !== 200 ||
			 ! isset( $data['ok'] ) ||
			 $data['ok'] !== true ) {
			return esc_html__( 'Please refer to bot creation!', 'csf' );
		}

		// Token is valid
		return;
	}

	/**
	 * Валидация и настройка webhook
	 * 
	 * Обрабатывает включение/выключение webhook режима.
	 * При включении устанавливает webhook в Telegram и отключает cron polling.
	 * При выключении удаляет webhook и включает cron polling.
	 * 
	 * @param mixed $value Значение переключателя webhook (true/false)
	 * @return string|void Сообщение об ошибке или void при успехе
	 */
	function csf_validate_webhook_setup( $value ) {

		// Проверяем доступность класса WP_TGComment
		if ( ! class_exists( 'WP_TGComment' ) ) {
			return 'Класс WP_TGComment не загружен';
		}
		// Получаем текущее состояние webhook
		$current_options = get_option( WP_TGCOMMENT_OPTIONS, array() );
		$current_webhook_enabled = isset( $current_options['webhook_enabled'] ) ? $current_options['webhook_enabled']=='1' : false;

		// Если включаем webhook
		if ( ! empty( $value ) ) {
			// Пытаемся установить webhook
			$setup_result = WP_TGComment::set_webhook();

			if ( ! $setup_result['success'] ) {
				return 'Не удалось установить webhook: ' . esc_html( $setup_result['message'] );
			}

			// Webhook успешно установлен
			error_log( 'WP TGComment: Webhook установлен через валидацию' );
			wp_clear_scheduled_hook( WP_TGComment::TELEGRAM_UPDATES_GET_CRON_HOOK );
			error_log( 'WP TGComment: wp_tgcomment_get_updates cron отключен через save_after хук' );
			delete_transient( 'wp_tgcomment_processing_lock' );
			delete_transient( 'wp_tgcomment_processor_lock' );
			return; // Валидация прошла успешно
		}

		// Если выключаем webhook и он был включен
		if ( empty( $value ) && $current_webhook_enabled ) {
			// Удаляем webhook
			$delete_result = WP_TGComment::delete_webhook();

			if ( ! $delete_result['success'] ) {
				error_log( 'WP TGComment: Ошибка удаления webhook в валидации: ' . $delete_result['message'] );
				if ( ! wp_next_scheduled( WP_TGComment::TELEGRAM_UPDATES_GET_CRON_HOOK ) ) {
					wp_schedule_event( time(), WP_TGComment::TELEGRAM_UPDATES_INTERVAL, WP_TGComment::TELEGRAM_UPDATES_GET_CRON_HOOK );
					error_log( 'WP TGComment: wp_tgcomment_get_updates cron включен через save_after хук' );
				}
				// Не возвращаем ошибку, так как удаление webhook не критично
			} else {
				error_log( 'WP TGComment: Webhook удален через валидацию' );
			}
		}

		return; // Валидация прошла
	}

} // Конец функции wp_tgcomment_create_settings

/**
 * Хук для управления cron после сохранения настроек webhook
 * 
 * Обрабатывает изменения настроек после сохранения формы.
 * Автоматически переключает между webhook и polling режимами,
 * управляя соответствующими cron задачами.
 * 
 * @param array $data Сохраненные данные настроек
 * @return void
 */
add_action( 'csf_wp-tgcomment_save_after', function( $data ) {

	try {
		// Получаем значение переключателя webhook
		$webhook_enabled = isset( $data['webhook_enabled'] ) ? $data['webhook_enabled']=='1' : false;
		if ( $webhook_enabled ) {
			// Webhook включен - отключаем cron забора сообщений
			wp_clear_scheduled_hook( WP_TGComment::TELEGRAM_UPDATES_GET_CRON_HOOK );
			error_log( 'WP TGComment: wp_tgcomment_get_updates cron отключен через save_after хук' );
			delete_transient( 'wp_tgcomment_processing_lock' );
			delete_transient( 'wp_tgcomment_processor_lock' );
		} else {
			// Webhook выключен - включаем cron забора сообщений
			if ( ! wp_next_scheduled( WP_TGComment::TELEGRAM_UPDATES_GET_CRON_HOOK ) ) {
				wp_schedule_event( time(), WP_TGComment::TELEGRAM_UPDATES_INTERVAL, WP_TGComment::TELEGRAM_UPDATES_GET_CRON_HOOK );
				error_log( 'WP TGComment: wp_tgcomment_get_updates cron включен через save_after хук' );
			}
		}
		error_log( 'WP TGComment: save_after хук завершен успешно' );
	} catch ( Exception $e ) {
		error_log( 'WP TGComment: Ошибка в save_after хуке: ' . $e->getMessage() );
	}
} );

/**
 * Генерация HTML для кнопок проверки статуса системы
 * 
 * Создает интерфейс с кнопками для проверки состояния различных
 * компонентов плагина: webhook, cron задач, обработчиков.
 * 
 * @return string HTML код с кнопками и контейнерами для результатов
 */
function wp_tgcomment_get_status_check_buttons_html() {
	$output = '<div class="wp-tgcomment-status-buttons">';

	$output .= '<div style="margin-bottom: 15px;">';
	$output .= '<button type="button" class="button button-primary" onclick="wpTgCommentCheckWebhookStatus()">Проверить статус webhook</button>';
	$output .= '<div id="webhook-status-result" style="margin-top: 10px;"></div>';
	$output .= '</div>';

	$output .= '<div style="margin-bottom: 15px;">';
	$output .= '<button type="button" class="button button-secondary" onclick="wpTgCommentCheckUpdatesCronStatus()">Проверить статус забора сообщений от телеграмма</button>';
	$output .= '<div id="updates-cron-status-result" style="margin-top: 10px;"></div>';
	$output .= '</div>';

	$output .= '<div style="margin-bottom: 15px;">';
	$output .= '<button type="button" class="button button-secondary" onclick="wpTgCommentCheckProcessorCronStatus()">Проверить статус обработки очереди</button>';
	$output .= '<div id="processor-cron-status-result" style="margin-top: 10px;"></div>';
	$output .= '</div>';

	$output .= '<div style="margin-bottom: 15px;">';
	$output .= '<button type="button" class="button button-secondary" onclick="wpTgCommentCheckNotifierCronStatus()">Проверить статус отправки уведомлений</button>';
	$output .= '<div id="notifier-cron-status-result" style="margin-top: 10px;"></div>';
	$output .= '</div>';

	$output .= '</div>';

	return $output;
}
