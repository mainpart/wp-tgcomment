<?php

/**
 * Класс для обработки авторизации через Telegram Login Widget
 *
 * Реализует полную криптографическую проверку данных авторизации согласно
 * официальному алгоритму Telegram: https://core.telegram.org/widgets/login#receiving-authorization-data
 *
 * Основные функции:
 * - Проверка подлинности данных от Telegram Login Widget
 * - Валидация HMAC-SHA256 подписи с секретным ключом
 * - Проверка актуальности данных авторизации (не старше 24 часов)
 * - Привязка Telegram ID к существующим пользователям WordPress
 * - Отправка уведомлений пользователям об успешной авторизации
 * - Отображение страницы подтверждения с автоматическим закрытием
 *
 * Алгоритм проверки:
 * 1. Получаем все параметры от Telegram (id, first_name, last_name, username, photo_url, auth_date, hash)
 * 2. Создаем data-check-string из всех полей кроме hash, отсортированных в алфавитном порядке
 * 3. Создаем secret_key как SHA256 хеш от токена бота
 * 4. Вычисляем HMAC-SHA-256 от data-check-string с secret_key
 * 5. Сравниваем с полученным hash
 * 6. Дополнительно проверяем, что данные не старше 24 часов
 */
class WP_TGComment_Auth {

	/**
	 * Инициализация системы авторизации
	 * 
	 * Регистрирует хук WordPress для обработки запросов авторизации
	 * через Telegram Login Widget на раннем этапе загрузки.
	 * 
	 * @return void
	 */
	public static function init() {
		// Обрабатываем запросы авторизации на более раннем этапе
		add_action( 'init', [ self::class, 'handle_telegram_auth' ] );

    }

	/**
	 * Проверка подлинности данных авторизации от Telegram
	 * 
	 * Реализует криптографическую проверку данных согласно официальному
	 * алгоритму Telegram. Создает data-check-string, вычисляет HMAC-SHA256
	 * с секретным ключом и сравнивает с полученным hash.
	 * 
	 * @param array $auth_data Массив данных авторизации от Telegram Login Widget
	 *                         Содержит: id, first_name, last_name, username, photo_url, auth_date, hash
	 * @return bool true если данные валидны, false если подделка или ошибка
	 */
	public static function verify_telegram_data( $auth_data ) {
		// Получаем токен бота
		$bot_token = WP_TGComment::get_telegram_token();

		if ( empty( $bot_token ) ) {
			error_log( 'WP TGComment Auth: Токен бота не настроен' );
			return false;
		}

		// Логируем полученные данные для отладки
		error_log( 'WP TGComment Auth: Полученные данные для проверки: ' . print_r( $auth_data, true ) );

		// Проверяем наличие hash
		if ( ! isset( $auth_data['hash'] ) ) {
			error_log( 'WP TGComment Auth: Отсутствует hash в данных авторизации' );
			return false;
		}

		$hash = $auth_data['hash'];
		unset( $auth_data['hash'] ); // Удаляем hash из данных

		// Создаем data-check-string из отсортированных параметров
		$data_check_array = array();
		foreach ( $auth_data as $key => $value ) {
			$data_check_array[] = $key . '=' . $value;
		}
		sort( $data_check_array );
		$data_check_string = implode( "\n", $data_check_array );

		// Логируем data-check-string для отладки
		error_log( 'WP TGComment Auth: Data check string: ' . $data_check_string );

		// Создаем секретный ключ как SHA256 хеш от токена бота
		$secret_key = hash( 'sha256', $bot_token, true );

		// Вычисляем HMAC-SHA-256 от data-check-string
		$expected_hash = hash_hmac( 'sha256', $data_check_string, $secret_key );

		// Логируем для сравнения
		error_log( 'WP TGComment Auth: Ожидаемый hash: ' . $expected_hash );
		error_log( 'WP TGComment Auth: Полученный hash: ' . $hash );

		// Сравниваем хеши
		if ( ! hash_equals( $expected_hash, $hash ) ) {
			error_log( 'WP TGComment Auth: Неверный hash!' );
			return false;
		}

		// Проверяем время авторизации (не старше 24 часов)
		if ( isset( $auth_data['auth_date'] ) ) {
			$auth_time = intval( $auth_data['auth_date'] );
			$current_time = time();

			// 24 часа = 86400 секунд
			if ( ( $current_time - $auth_time ) > 86400 ) {
				error_log( 'WP TGComment Auth: Данные авторизации устарели. Auth time: ' . $auth_time . ', current time: ' . $current_time );
				return false;
			}
		}

		error_log( 'WP TGComment Auth: Проверка данных прошла успешно!' );
		return true;
	}

	/**
	 * Обработка запроса авторизации через Telegram Login Widget
	 * 
	 * Основная функция обработки GET запроса с параметрами авторизации.
	 * Проверяет подлинность данных, привязывает Telegram ID к пользователю WordPress,
	 * отправляет уведомление в Telegram и показывает страницу подтверждения.
	 * 
	 * @return void Завершается exit после обработки
	 */
	public static function handle_telegram_auth() {
		// Проверяем, есть ли параметры авторизации
		if ( ! isset( $_GET['tg_auth'] ) || $_GET['tg_auth'] !== '1' ) {
			return;
		}

		// Список возможных параметров от Telegram Login Widget
		$telegram_params = array( 'id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash' );

		foreach ( $telegram_params as $param ) {
			if ( isset( $_GET[$param] ) ) {
				$telegram_auth_data[$param] = $_GET[$param];
			}
		}

		// Проверяем, что есть основные данные
		if ( ! isset( $telegram_auth_data['id'] ) || ! isset( $telegram_auth_data['hash'] ) ) {

            wp_die( 'Неверные параметры авторизации: отсутствуют обязательные поля' );
		}

		// Проверяем подлинность данных от Telegram
		if ( ! self::verify_telegram_data( $telegram_auth_data ) ) {

             wp_die( 'Ошибка проверки данных авторизации от Telegram' );
		}

		$telegram_id = intval( $telegram_auth_data['id'] );
		// Логируем успешную проверку
		error_log( 'WP TGComment Auth: Данные от Telegram успешно проверены для пользователя ID: ' . $telegram_id );

		// Проверяем, авторизован ли уже пользователь
		error_log( 'WP TGComment Auth: Проверяем авторизацию пользователя. is_user_logged_in: ' . (is_user_logged_in() ? 'true' : 'false') );
		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			error_log( 'WP TGComment Auth: Пользователь авторизован. ID: ' . $current_user->ID . ', email: ' . $current_user->user_email );

			// Сначала отвязываем этот Telegram ID от других пользователей
			$existing_users = get_users( array(
				'meta_key'   => USER_META_KEY,
				'meta_value' => $telegram_id,
				'exclude'    => array( $current_user->ID ), // Исключаем текущего пользователя
				'fields'     => 'ID'
			) );

			if ( ! empty( $existing_users ) ) {
				foreach ( $existing_users as $user_id ) {
					delete_user_meta( $user_id, USER_META_KEY );
					error_log( "WP TGComment Auth: Отвязан Telegram ID {$telegram_id} от пользователя {$user_id}" );
				}
			}

			// Привязываем Telegram ID к текущему пользователю
			update_user_meta( $current_user->ID, USER_META_KEY, $telegram_id );
			error_log( "WP TGComment Auth: Привязан Telegram ID {$telegram_id} к пользователю {$current_user->ID}" );
			
			// Уведомляем пользователя в Telegram об успешной привязке
			self::notify_telegram_user( $telegram_id, $current_user );
			error_log( 'WP TGComment Auth: Показываем страницу закрытия и завершаем выполнение' );
            self::show_close_page();
            exit;
		} else {
			// Пользователь не авторизован - показываем форму входа с информацией о Telegram
			error_log( 'WP TGComment Auth: Пользователь не авторизован, перенаправляем на страницу входа' );
            wp_redirect(wp_login_url($_SERVER['REQUEST_URI']));
            exit;
		}
	}

	/**
	 * Отображение страницы успешной авторизации
	 * 
	 * Генерирует HTML страницу с подтверждением успешной операции.
	 * Включает JavaScript для автоматического закрытия окна/вкладки
	 * и возврата пользователя в Telegram.
	 * 
	 * @return void Выводит HTML и завершает выполнение
	 */
	public static function show_close_page(  ) {
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>Авторизация завершена - <?php bloginfo( 'name' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
					display: flex;
					justify-content: center;
					align-items: center;
					min-height: 100vh;
					margin: 0;
					background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
					color: white;
					text-align: center;
					padding: 20px;
				}
				.container {
					background: rgba(255,255,255,0.1);
					backdrop-filter: blur(10px);
					border-radius: 20px;
					padding: 40px;
					max-width: 400px;
					box-shadow: 0 8px 32px rgba(31, 38, 135, 0.37);
					border: 1px solid rgba(255, 255, 255, 0.18);
				}
				.success-icon {
					font-size: 64px;
					margin-bottom: 20px;
					animation: pulse 2s infinite;
				}
				@keyframes pulse {
					0% { transform: scale(1); }
					50% { transform: scale(1.1); }
					100% { transform: scale(1); }
				}
				h1 { margin: 0 0 20px 0; font-size: 24px; }
				p { margin: 10px 0; line-height: 1.5; }
				.countdown { font-size: 18px; font-weight: bold; margin-top: 20px; }
				.manual-close {
					background: rgba(255,255,255,0.2);
					border: 1px solid rgba(255,255,255,0.3);
					border-radius: 10px;
					padding: 15px;
					margin-top: 20px;
				}
			</style>
		</head>
		<body>
			<div class="container">
				<div class="success-icon">✅</div>
				<h1>Авторизация завершена!</h1>
				<p>Ваш аккаунт успешно привязан к Telegram.</p>
				<p>Вернитесь в Telegram для продолжения работы.</p>
				<div class="countdown" id="countdown"></div>
				<div class="manual-close" id="manualClose" style="display:none;">
					<p><strong>Закройте это окно вручную:</strong></p>
					<p>• Нажмите кнопку "Назад" в браузере<br>
					• Или закройте вкладку/окно браузера<br>
					• Затем вернитесь в Telegram</p>
				</div>
			</div>

			<script>
				let countdown = 3;
				const countdownEl = document.getElementById('countdown');
				const manualCloseEl = document.getElementById('manualClose');
				
				function updateCountdown() {
					countdownEl.textContent = `Попытка автозакрытия через ${countdown} сек...`;
					countdown--;
					
					if (countdown < 0) {
						attemptClose();
					} else {
						setTimeout(updateCountdown, 1000);
					}
				}
				
				function attemptClose() {
					let closed = false;
					
					// Метод 1: Закрытие popup-окна
					if (window.opener) {
						try {
							window.close();
							closed = true;
						} catch(e) {}
					}
					
					// Метод 2: Закрытие через history.back() (для вкладок)
					if (!closed && window.history.length > 1) {
						try {
							window.history.back();
							closed = true;
						} catch(e) {}
					}
					
					// Метод 3: Telegram WebApp API (если доступен)
					if (!closed && window.Telegram && window.Telegram.WebApp) {
						try {
							window.Telegram.WebApp.close();
							closed = true;
						} catch(e) {}
					}
					
					// Если ничего не сработало - показываем инструкции
					if (!closed) {
						countdownEl.style.display = 'none';
						manualCloseEl.style.display = 'block';
					}
				}
				
				// Начинаем отсчет
				updateCountdown();
				
				// Дополнительная попытка закрытия при загрузке
				window.addEventListener('load', function() {
					setTimeout(attemptClose, 100);
				});
			</script>
		</body>
		</html>
		<?php
	}

	/**
	 * Отправка уведомления в Telegram об успешной авторизации
	 * 
	 * Формирует информативное сообщение с данными пользователя WordPress
	 * и отправляет его в Telegram через WP_TGComment_Handler.
	 * 
	 * @param int     $telegram_id ID пользователя в Telegram
	 * @param WP_User $user        Объект пользователя WordPress
	 * @return void
	 */
	public static function notify_telegram_user( $telegram_id, $user ) {
		$message = "🎉 <b>Успешная авторизация!</b>\n\n";
		$message .= "Ваш Telegram аккаунт привязан к пользователю:\n";
		$message .= "👤 <b>" . esc_html( $user->display_name ) . "</b>\n";
		$message .= "📧 " . esc_html( $user->user_email ) . "\n\n";
		$message .= "Теперь вы можете использовать команду /start для доступа к вашим данным.";

		WP_TGComment_Handler::send_message( $telegram_id, $message );
	}



}
