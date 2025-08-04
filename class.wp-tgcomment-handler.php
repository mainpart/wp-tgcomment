<?php

/**
 * Класс для обработки сообщений от Telegram Bot API
 *
 * Основные функции:
 * - Обработка входящих сообщений от пользователей Telegram
 * - Авторизация пользователей через Telegram Login Widget
 * - Отправка сообщений и уведомлений в Telegram
 * - Управление консультациями между докторами и клиентами
 * - Сохранение входящих сообщений в базу данных для последующей обработки
 */
class WP_TGComment_Handler {

	const TABLE_INCOMING = 'wp_tgcomments_incoming';
	const USER_TELEGRAM_POST_ID_META = 'wp_telegram_post_id';

	// Константы для обработки комментариев
	const COMMENT_ATTACHMENTS_META = 'tg_comment_attachments';
	const MAX_RETRY_ATTEMPTS = 3;
	const PROCESSING_BATCH_SIZE = 10;
	const COMMENT_PROCESSOR_CRON_HOOK = 'wp_tgcomment_process_comments';
	const COMMENT_PROCESSOR_INTERVAL = 'wp_tgcomment_every_minute';

	/**
	 * Очистка HTML контента от неподдерживаемых Telegram тегов
	 *
	 * Основано на официальной документации Telegram Bot API.
	 * Оставляет только теги, поддерживаемые parse_mode="HTML".
	 * Конвертирует неподдерживаемые теги в читаемый текст.
	 *
	 * @param string $content HTML контент для очистки
	 * @return string Очищенный контент, безопасный для Telegram
	 */
	public static function sanitize_telegram_html( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		// Поддерживаемые теги согласно официальной документации Telegram Bot API
		$supported_tags = array(
			'b' => array(),
			'strong' => array(),
			'i' => array(),
			'em' => array(),
			'u' => array(),
			'ins' => array(),
			's' => array(),
			'strike' => array(),
			'del' => array(),
			'span' => array('class'), // только class="tg-spoiler"
			'tg-spoiler' => array(),
			'a' => array('href'),
			'code' => array(),
			'pre' => array(),
			'blockquote' => array('expandable'),
			'tg-emoji' => array('emoji-id'),
		);

		// Специальные конверсии для неподдерживаемых тегов
		$conversions = array(
			// Списки конвертируем в текст с символами
			'/<ol[^>]*>/i' => "\n",
			'/<\/ol>/i' => "\n",
			'/<ul[^>]*>/i' => "\n",
			'/<\/ul>/i' => "\n",
			'/<li[^>]*>/i' => '• ',
			'/<\/li>/i' => "\n",

			// Заголовки конвертируем в жирный текст
			'/<h[1-6][^>]*>/i' => '<b>',
			'/<\/h[1-6]>/i' => '</b>' . "\n\n",

			// Блочные элементы
			'/<div[^>]*>/i' => '',
			'/<\/div>/i' => "\n",
			'/<p[^>]*>/i' => '',
			'/<\/p>/i' => "\n\n",

			// Переносы строк
			'/<br\s*\/?>/i' => "\n",
			'/<hr\s*\/?>/i' => "\n" . str_repeat('━', 20) . "\n",

			// Цитаты (если не поддерживается старая версия API)
			'/<q[^>]*>/i' => '"',
			'/<\/q>/i' => '"',

			// Таблицы удаляем полностью
			'/<table[^>]*>.*?<\/table>/is' => "\n[Таблица не поддерживается]\n",

			// Опасные теги
			'/<script[^>]*>.*?<\/script>/is' => '',
			'/<style[^>]*>.*?<\/style>/is' => '',
			'/<iframe[^>]*>.*?<\/iframe>/is' => '',
			'/<object[^>]*>.*?<\/object>/is' => '',
			'/<embed[^>]*>/i' => '',
			'/<form[^>]*>.*?<\/form>/is' => '',
			'/<input[^>]*>/i' => '',
			'/<textarea[^>]*>.*?<\/textarea>/is' => '',
			'/<select[^>]*>.*?<\/select>/is' => '',
			'/<button[^>]*>.*?<\/button>/is' => '',
		);

		// Применяем конверсии
		$content = preg_replace( array_keys( $conversions ), array_values( $conversions ), $content );

		// Создаем DOMDocument для более точной очистки
		$dom = new DOMDocument('1.0', 'UTF-8');
		libxml_use_internal_errors(true);

		// Оборачиваем в HTML структуру для корректного парсинга
		$wrapped_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';

		if ($dom->loadHTML($wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
			$body = $dom->getElementsByTagName('body')->item(0);
			if ($body) {
				self::clean_unsupported_tags($body, $supported_tags, $dom);

				// Извлекаем очищенный контент
				$cleaned = '';
				foreach ($body->childNodes as $child) {
					$cleaned .= $dom->saveHTML($child);
				}
				$content = $cleaned;
			}
		}

		libxml_clear_errors();

		// Финальная очистка
		$content = self::final_cleanup($content);

		return $content;
	}

	/**
	 * Рекурсивная очистка неподдерживаемых тегов
	 */
	private static function clean_unsupported_tags($node, $supported_tags, $dom) {
		$nodes_to_remove = array();

		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE) {
				$tag_name = strtolower($child->tagName);

				if (isset($supported_tags[$tag_name])) {
					// Тег поддерживается - очищаем атрибуты
					$allowed_attrs = $supported_tags[$tag_name];
					$attrs_to_remove = array();

					if ($child->hasAttributes()) {
						foreach ($child->attributes as $attr) {
							$attr_name = strtolower($attr->name);

							// Специальная проверка для span class="tg-spoiler"
							if ($tag_name === 'span' && $attr_name === 'class') {
								if (trim($attr->value) !== 'tg-spoiler') {
									$attrs_to_remove[] = $attr_name;
								}
							}
							// Для остальных тегов проверяем белый список
							elseif (!in_array($attr_name, $allowed_attrs)) {
								$attrs_to_remove[] = $attr_name;
							}
						}
					}

					// Удаляем неразрешенные атрибуты
					foreach ($attrs_to_remove as $attr_name) {
						$child->removeAttribute($attr_name);
					}

					// Рекурсивно очищаем дочерние элементы
					self::clean_unsupported_tags($child, $supported_tags, $dom);
				} else {
					// Тег не поддерживается - заменяем содержимым
					$nodes_to_remove[] = $child;
				}
			}
		}

		// Заменяем неподдерживаемые узлы их содержимым
		foreach ($nodes_to_remove as $node_to_remove) {
			while ($node_to_remove->firstChild) {
				$node->insertBefore($node_to_remove->firstChild, $node_to_remove);
			}
			$node->removeChild($node_to_remove);
		}
	}

	/**
	 * Финальная очистка текста
	 */
	private static function final_cleanup($content) {
		// Экранируем специальные HTML символы
		$content = str_replace(array('&lt;', '&gt;', '&amp;'), array('<', '>', '&'), $content);

		// Убираем лишние переносы строк
		$content = preg_replace('/\n{3,}/', "\n\n", $content);

		// Убираем пробелы в начале и конце строк
		$lines = explode("\n", $content);
		$lines = array_map('trim', $lines);
		$content = implode("\n", $lines);

		// Убираем лишние пробелы вокруг bullet points
		$content = preg_replace('/\n+• /', "\n• ", $content);

		return trim($content);
	}

	/**
	 * Основная функция обработки входящих сообщений от Telegram
	 *
	 * Определяет тип сообщения (команда или обычное сообщение) и направляет
	 * на соответствующий обработчик. Проверяет авторизацию пользователя.
	 *
	 * @param array $message Массив данных сообщения от Telegram API
	 *                       Содержит: text, from, chat, message_id и другие поля
	 * @return void
	 */
	public static function process_message( $message ) {

		$text = isset( $message['text'] ) ? trim( $message['text'] ) : '';
		$from = isset( $message['from'] ) ? $message['from'] : null;
		$chat_id = isset( $message['chat']['id'] ) ? $message['chat']['id'] : null;

		if ( ! $from || ! $chat_id ) {
			error_log( 'WP TGComment Handler: Неполные данные сообщения' );
			return;
		}

		$telegram_user_id = $from['id'];

		// Ищем связанного пользователя WordPress
		$wp_user = self::find_wp_user_by_telegram_id( $telegram_user_id );

		// Если пользователь не найден - показываем кнопку авторизации для любого сообщения
		if ( ! $wp_user ) {
			self::show_login_button( $chat_id, $telegram_user_id );
			return;
		}
		// Пользователь найден - обрабатываем команды и сообщения
		if ( strpos( $text, '/start' ) === 0 ) {
			// Показываем данные пользователя и его записи
			self::show_user_data( $chat_id, $wp_user );
		} elseif ( strpos( $text, '/list' ) === 0 ) {
			// Показываем последние 10 сообщений из активной консультации
			self::show_last_messages( $chat_id, $wp_user );
		} elseif ( strpos( $text, '/logout' ) === 0 ) {
			// Выходим из системы - удаляем привязку к Telegram
			self::handle_logout( $chat_id, $wp_user, $telegram_user_id );
		} else {
			// Обрабатываем сообщения (текст + медиафайлы)
			self::handle_user_telegram_message( $chat_id, $wp_user, $telegram_user_id, $message );
		}
	}

	/**
	 * Поиск пользователя WordPress по Telegram ID
	 *
	 * Ищет в базе данных WordPress пользователя, у которого в метаполе
	 * USER_META_KEY сохранен указанный Telegram ID.
	 *
	 * @param int $telegram_user_id ID пользователя в Telegram
	 * @return WP_User|null Объект пользователя WordPress или null если не найден
	 */
	public static function find_wp_user_by_telegram_id( $telegram_user_id ) {
		$users = get_users( array(
			'meta_key'   => USER_META_KEY,
			'meta_value' => $telegram_user_id,
			'number'     => 1
		) );

		return ! empty( $users ) ? $users[0] : null;
	}

	/**
	 * Отображение данных пользователя и доступных консультаций
	 *
	 * Показывает список активных консультаций пользователя с кнопками для выбора.
	 * Автоматически устанавливает активную консультацию если её нет.
	 *
	 * @param int     $chat_id   ID чата в Telegram
	 * @param WP_User $wp_user   Объект пользователя WordPress
	 * @return void
	 */
	public static function show_user_data( $chat_id, $wp_user ) {
		// Получаем записи пользователя
		$user_posts = self::get_user_posts( $wp_user->ID );

		// Получаем текущий выбранный пост (с проверкой и автоустановкой)
		$current_post = self::ensure_user_has_telegram_post_id( $wp_user->ID );

		if ( $user_posts->have_posts()  ) {
			$message = "👋 Привет, {$wp_user->display_name}!\n\n";

			// Создаем кнопки для выбора записей
			$keyboard = [
				'inline_keyboard' => []
			];

			foreach ( $user_posts->posts as $post ) {
				// Проверяем статус активности консультации
				$is_active = get_post_meta( $post->ID, 'is_active', true );
				$status_icon = ( $is_active == '1' ) ? '✅' : '❌';

				$button_text = $status_icon . ' ' . $post->post_title;

				if ( $current_post && $current_post->ID == $post->ID ) {
					$button_text = "👉 " . $button_text;
					// Добавляем информацию о текущей консультации в сообщение
					$message .= "📌 Текущая консультация: {$status_icon} {$post->post_title}\n\n";
				}

				$keyboard['inline_keyboard'][] = [
					[
						'text' => $button_text,
						'callback_data' => 'select_post_' . $post->ID,
						'allowed_updates' => ['message', 'callback_query']
					]
				];
			}

			$message .= "Выберите консультацию для отправки сообщений:";

			self::send_message( $chat_id, $message, $keyboard );
		} else {
			$message = "👋 Привет, {$wp_user->display_name}!\n\n";
			$message .= "📝 У вас нет активных консультаций.\n\n";
			$message .= "Для отправки сообщений через Telegram необходимо иметь хотя бы одну активную запись.";
			self::send_message( $chat_id, $message );
		}
	}

	/**
	 * Отображение последних сообщений из активной консультации
	 *
	 * Показывает последние 3 сообщения из текущей активной консультации
	 * пользователя с информацией об авторах и времени отправки.
	 *
	 * @param int     $chat_id   ID чата в Telegram
	 * @param WP_User $wp_user   Объект пользователя WordPress
	 * @return void
	 */
	public static function show_last_messages( $chat_id, $wp_user ) {
		// Получаем активную консультацию пользователя
		$current_post = self::ensure_user_has_telegram_post_id( $wp_user->ID );

		if ( ! $current_post ) {
			$message = "❌ У вас нет активной консультации.\n\n";
			$message .= "Используйте команду /start для выбора консультации.";
			self::send_message( $chat_id, $message );
			return;
		}

		// Временно отключаем все фильтры the_comments чтобы обойти блокировки от других плагинов
		remove_filter( 'the_comments', [ Pharma::class, 'the_comments' ] );

		// Получаем последние 10 комментариев к активной консультации
		$comments = get_comments( array(
			'post_id' => $current_post->ID,
			'status' => 'approve',
			'number' => 3,
			'orderby' => 'comment_date',
			'order' => 'DESC',
		) );

		if ( empty( $comments ) ) {
			$message = "📝 В консультации \"{$current_post->post_title}\" пока нет сообщений.\n\n";
			$message .= "Отправьте первое сообщение!";
			self::send_message( $chat_id, $message );
			return;
		}

		// Формируем сообщение с последними комментариями
		$message = "📝 <b>Последние " . count($comments) . " сообщений</b>\n";
		$message .= "📌 Консультация: <b>{$current_post->post_title}</b>\n\n";

		// Переворачиваем массив чтобы показать сообщения в хронологическом порядке
		$comments = array_reverse( $comments );

		foreach ( $comments as $comment ) {
			$author_name = $comment->comment_author;
			$date = date( 'd.m.Y H:i', strtotime( $comment->comment_date ) );
			$content = $comment->comment_content;

			// Проверяем есть ли вложения
			$attachment_ids = get_comment_meta( $comment->comment_ID, 'attachment_id', true );
			$has_attachments = ! empty( $attachment_ids );
			$attachment_icon = $has_attachments ? ' 📎' : '';

			$message .= "👤 <b>{$author_name}</b> <i>{$date}</i>{$attachment_icon}\n";
			$message .= "{$content}\n\n";
		}

		$message .= "💬 Отправьте новое сообщение в этом чате для добавления в консультацию.";

		self::send_message( $chat_id, $message );
	}

	/**
	 * Отображение кнопки авторизации через Telegram Login Widget
	 *
	 * Создает inline кнопку с URL для авторизации через официальный
	 * Telegram Login Widget. Автоматически конвертирует HTTP в HTTPS.
	 *
	 * @param int $chat_id           ID чата в Telegram
	 * @param int $telegram_user_id  ID пользователя в Telegram
	 * @return void
	 */
	public static function show_login_button( $chat_id, $telegram_user_id ) {
		$site_url = get_site_url() . "/";
		// Принудительно используем HTTPS для Telegram Login URL
		$site_url = str_replace('http://', 'https://', $site_url);
		$bot_username = self::get_bot_username();

		// Создаем URL для авторизации через Telegram
				// Telegram Login Widget автоматически добавит параметры: id, first_name, last_name, username, photo_url, auth_date, hash

		$auth_url = add_query_arg( array(
			'tg_auth' => '1',
		), $site_url );
		error_log("URL $auth_url");
		$message = "👋 Привет!\n\n";
		$message .= "🔐 Для доступа к вашим данным необходимо войти на сайт через Telegram.\n\n";
		$message .= "Нажмите кнопку ниже для авторизации:";

		$keyboard = [
			'inline_keyboard' => [
				[
					[
						'text' => '🔑 Войти через Telegram',
						'login_url' => [
							'url' => $auth_url,
							'bot_username' => $bot_username,
							'request_write_access' => true,

						]
					]
				]
			]
		];

		self::send_message( $chat_id, $message, $keyboard );

		error_log( "WP TGComment: Запрос авторизации для Telegram ID: {$telegram_user_id}" );
	}

	/**
	 * Обработка сообщений от авторизованного пользователя
	 *
	 * Определяет контекст сообщения (ответ на другое сообщение или новое),
	 * находит соответствующую консультацию и сохраняет сообщение в очередь обработки.
	 *
	 * @param int     $chat_id           ID чата в Telegram
	 * @param WP_User $wp_user           Объект пользователя WordPress
	 * @param int     $telegram_user_id  ID пользователя в Telegram
	 * @param array   $message           Данные сообщения от Telegram API
	 * @return void
	 */
	public static function handle_user_telegram_message( $chat_id, $wp_user, $telegram_user_id, $message ) {
		$post_id = null;
		$reply_info = "";

		// Проверяем, отвечает ли пользователь на сообщение
		if ( isset( $message['reply_to_message']['message_id'] ) ) {
			$replied_message_id = $message['reply_to_message']['message_id'];
			$replied_message_from_id = $message['reply_to_message']['from']['id'];
			error_log( "WP TGComment Handler: Пользователь {$wp_user->ID} отвечает на сообщение Telegram ID: {$replied_message_id}" );

			// Ищем комментарий по telegram message_id
			$post_id = self::find_reply_comment_by_telegram_message_id( $telegram_user_id, $replied_message_id, $replied_message_from_id, $message );

			if ( $post_id ) {
				$reply_info = " [Ответ на сообщение #{$replied_message_id}]";
				error_log( "WP TGComment Handler: ✅ НАЙДЕН ПОСТ: {$post_id}" );
			} else {
				error_log( "WP TGComment Handler: ❌ Комментарий для ответа не найден, используем активную консультацию" );
			}
		}

		// Если не найден пост из ответа, используем активную консультацию пользователя
		if ( ! $post_id ) {
			if (user_can($wp_user,'manage_options')) {
				self::send_message($chat_id, "Админы не могут писать сообщения без ответов конкретному пользователю." );
				return;
			}
			$post_id = self::ensure_user_has_telegram_post_id( $wp_user->ID );

			if ( ! $post_id ) {
				// У пользователя нет доступных записей
				$msg = "❌ У вас нет активных консультаций.\n\n";
				$msg .= "Для отправки сообщений необходимо иметь хотя бы одну активную запись.";
				self::send_message( $chat_id, $msg );
				error_log( "WP TGComment: Пользователь {$wp_user->display_name} (ID: {$wp_user->ID}) пытался отправить сообщение без активных записей" );
				return;
			}

			error_log( "WP TGComment: Пользователь {$wp_user->display_name} (ID: {$wp_user->ID}) отправляет сообщение в активную консультацию {$post_id}" );

		}

		// Сохраняем сообщение в базу данных
		$message_id = self::save_incoming_message( $wp_user->ID, $telegram_user_id, $post_id, $message );

		if ( $message_id ) {

			// Ставим лайк на сообщение вместо текстового подтверждения
			$telegram_message_id = isset( $message['message_id'] ) ? $message['message_id'] : null;
			if ( $telegram_message_id ) {
				self::send_reaction( $chat_id, $telegram_message_id, '👍' );
			}

			$post_title = get_the_title( $post_id );
			error_log( "WP TGComment: Сохранено сообщение #{$message_id} для пользователя {$wp_user->display_name} (ID: {$wp_user->ID}) к записи '{$post_title}' (ID: {$post_id}){$reply_info}" );
		} else {
			self::send_message( $chat_id, "❌ Ошибка сохранения сообщения" );
		}
	}

	/**
	 * Обработка команды /logout - отключение пользователя от Telegram
	 *
	 * Удаляет привязку аккаунта WordPress к Telegram ID и очищает
	 * метаданные активной консультации.
	 *
	 * @param int     $chat_id           ID чата в Telegram
	 * @param WP_User $wp_user           Объект пользователя WordPress
	 * @param int     $telegram_user_id  ID пользователя в Telegram
	 * @return void
	 */
	public static function handle_logout( $chat_id, $wp_user, $telegram_user_id ) {
		// Удаляем метаполе связывающее пользователя с Telegram
		$deleted_tg_meta = delete_user_meta( $wp_user->ID, USER_META_KEY );

		// Удаляем метаполе активной консультации
		$deleted_post_meta = delete_user_meta( $wp_user->ID, self::USER_TELEGRAM_POST_ID_META );

		if ( $deleted_tg_meta ) {
			$message = "👋 До свидания, {$wp_user->display_name}!\n\n";
			$message .= "🔓 Вы успешно вышли из системы.\n\n";
			$message .= "Привязка вашего аккаунта к Telegram удалена.\n";
			$message .= "Для повторного входа отправьте любое сообщение.";

			self::send_message( $chat_id, $message );

			error_log( "WP TGComment: Пользователь {$wp_user->display_name} (ID: {$wp_user->ID}) вышел из системы. Telegram ID: {$telegram_user_id}" );
		} else {
			$message = "❌ Ошибка при выходе из системы.\n\n";
			$message .= "Попробуйте еще раз или обратитесь к администратору.";

			self::send_message( $chat_id, $message );

			error_log( "WP TGComment: Ошибка при выходе пользователя {$wp_user->display_name} (ID: {$wp_user->ID}) из системы" );
		}
	}

	/**
	 * Сохранение входящего сообщения в таблицу очереди обработки
	 *
	 * Извлекает данные из сообщения Telegram и сохраняет в таблицу incoming
	 * для последующей обработки cron задачей в комментарии WordPress.
	 *
	 * @param int   $wp_user_id       ID пользователя WordPress
	 * @param int   $telegram_user_id ID пользователя в Telegram
	 * @param int   $post_id          ID записи для создания комментария
	 * @param array $message          Данные сообщения от Telegram API
	 * @return int|false ID созданной записи или false при ошибке
	 */
	public static function save_incoming_message( $wp_user_id, $telegram_user_id, $post_id, $message ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_INCOMING;

		// Извлекаем данные из сообщения
		$media_group_id = isset( $message['media_group_id'] ) ? $message['media_group_id'] : null;
		$chat_id = $message['chat']['id'];
		$tg_message_id = $message['message_id'];

		$result = $wpdb->insert(
			$table_name,
			array(
				'wp_user_id' => $wp_user_id,
				'telegram_user_id' => $telegram_user_id,
				'chat_id' => $chat_id,
				'tg_message_id' => $tg_message_id,
				'post_id' => $post_id,
				'telegram_message_json' => json_encode( $message ),
				'media_group_id' => $media_group_id,
				'created_at' => current_time( 'mysql' ),
				'retry_count' => 0
			),
			array(
				'%d', // wp_user_id
				'%d', // telegram_user_id
				'%d', // chat_id
				'%d', // tg_message_id
				'%d', // post_id
				'%s', // telegram_message_json
				'%s', // media_group_id
				'%s', // created_at
				'%d'  // retry_count
			)
		);


		return $result !== false ? $wpdb->insert_id : false;
	}

	/**
	 * Отправка сообщения в Telegram через Bot API
	 *
	 * Отправляет текстовое сообщение с опциональной клавиатурой.
	 * Автоматически очищает HTML от неподдерживаемых тегов. При ошибке парсинга
	 * HTML пытается отправить сообщение без форматирования. Сохраняет связь с комментарием WordPress.
	 *
	 * @param int        $chat_id    ID чата в Telegram
	 * @param string     $text       Текст сообщения (может содержать HTML)
	 * @param array|null $keyboard   Массив inline клавиатуры (опционально)
	 * @param int|null   $comment_id ID комментария WordPress для связи (опционально)
	 * @return int|false ID отправленного сообщения в Telegram или false при ошибке
	 */
	public static function send_message( $chat_id, $text, $keyboard = null, $comment_id = null ) {
		$token = WP_TGComment::get_telegram_token();

		if ( empty( $token ) ) {
			error_log( 'WP TGComment Handler: Telegram bot token не настроен' );
			return false;
		}

		// Принудительно конвертируем в UTF-8 если нужно
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			error_log( 'WP TGComment Handler: Конвертируем текст в UTF-8 (была битая кодировка)' );
			$text = mb_convert_encoding( $text, 'UTF-8', mb_detect_encoding( $text ) );
		}

		// Применяем санитизацию HTML для Telegram
		$sanitized_text = self::sanitize_telegram_html( $text );

		$url = "https://api.telegram.org/bot{$token}/sendMessage";

		$data = array(
			'chat_id' => $chat_id,
			'text' => $sanitized_text,
			'parse_mode' => 'HTML'
		);

		if ( $keyboard ) {
			$data['reply_markup'] = json_encode( $keyboard );
		}

		$response = wp_remote_post( $url, array(
			'body' => $data,
			'timeout' => 15
		) );

		if ( is_wp_error( $response ) ) {
			error_log( 'WP TGComment Handler: Ошибка отправки сообщения: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! $result || ! isset( $result['ok'] ) || ! $result['ok'] ) {
			// Проверяем, связана ли ошибка с HTML тегами
			if ( $code === 400 && isset( $result['description'] ) &&
				 ( strpos( $result['description'], "can't parse entities" ) !== false ||
				   strpos( $result['description'], "Bad Request" ) !== false ) ) {

				// Пытаемся отправить через fallback метод
				$fallback_result = self::send_message_fallback( $chat_id, $text, $keyboard, $url, $result['description'] );
				if ( $fallback_result ) {
					$result = $fallback_result; // Используем результат fallback отправки
				} else {
					return false;
				}
			} else {
				error_log( 'WP TGComment Handler: Неверный ответ API при отправке. Код:' . $code . ' Ответ:' . $body );

				// Специальная обработка для 403 - бот заблокирован пользователем
				if ( $code === 403 ) {
					do_action('wp_tg_sendmessage_403', $chat_id);
				}

				return false;
			}
		}
		//error_log( "WP TGComment Handler: ".var_export($result, true) );
		$telegram_message_id = $result['result']['message_id'];

		// Если сообщение успешно отправлено и передан comment_id, сохраняем связь
		if ( $comment_id ) {
			add_comment_meta( $comment_id, 'tg_outgoing_message_id', $telegram_message_id, false );
			add_comment_meta( $comment_id, 'tg_outgoing_chat_id', $chat_id, false );
			error_log( "WP TGComment Handler: Сохранена связь комментария {$comment_id} с Telegram сообщением {$telegram_message_id} в чате {$chat_id}" );
		}

		return $telegram_message_id;
	}

	/**
	 * Fallback отправка сообщения без HTML форматирования
	 *
	 * Убирает все HTML теги и отправляет сообщение как обычный текст
	 * при ошибках парсинга HTML в основном методе отправки.
	 *
	 * @param int         $chat_id     ID чата в Telegram
	 * @param string      $text        Оригинальный текст сообщения
	 * @param array|null  $keyboard    Массив inline клавиатуры (опционально)
	 * @param string      $url         URL для отправки к Telegram API
	 * @param string      $error_msg   Сообщение об ошибке для логирования
	 * @return array|false Результат API ответа или false при ошибке
	 */
	private static function send_message_fallback( $chat_id, $text, $keyboard, $url, $error_msg ) {
		error_log( 'WP TGComment Handler: Ошибка парсинга HTML, пробуем отправить без тегов. Ошибка: ' . $error_msg );

		// Убираем все HTML теги и пробуем отправить еще раз
		$plain_text = wp_strip_all_tags( $text );

		$fallback_data = array(
			'chat_id' => $chat_id,
			'text' => $plain_text,
			'parse_mode' => null // Убираем parse_mode
		);

		if ( $keyboard ) {
			$fallback_data['reply_markup'] = json_encode( $keyboard );
		}

		$fallback_response = wp_remote_post( $url, array(
			'body' => $fallback_data,
			'timeout' => 15
		) );

		if ( is_wp_error( $fallback_response ) ) {
			error_log( 'WP TGComment Handler: ❌ Fallback отправка завершилась ошибкой: ' . $fallback_response->get_error_message() );
			return false;
		}

		$fallback_code = wp_remote_retrieve_response_code( $fallback_response );
		$fallback_body = wp_remote_retrieve_body( $fallback_response );
		$fallback_result = json_decode( $fallback_body, true );

		if ( $fallback_result && isset( $fallback_result['ok'] ) && $fallback_result['ok'] ) {
			error_log( 'WP TGComment Handler: ✅ Сообщение отправлено после удаления HTML тегов' );
			return $fallback_result;
		} else {
			error_log( 'WP TGComment Handler: ❌ Fallback отправка тоже не удалась. Код:' . $fallback_code . ' Ответ:' . $fallback_body );
			return false;
		}
	}

	/**
	 * Отправка эмодзи реакции на сообщение в Telegram
	 *
	 * Использует API setMessageReaction для установки эмодзи реакции
	 * на конкретное сообщение. По умолчанию ставит "👍".
	 *
	 * @param int    $chat_id    ID чата в Telegram
	 * @param int    $message_id ID сообщения для реакции
	 * @param string $reaction   Эмодзи для реакции (по умолчанию '👍')
	 * @return bool true при успехе, false при ошибке
	 */
	public static function send_reaction( $chat_id, $message_id, $reaction = '👍' ) {
		$token = WP_TGComment::get_telegram_token();

		if ( empty( $token ) ) {
			error_log( 'WP TGComment Handler: Telegram bot token не настроен' );
			return false;
		}

		$url = "https://api.telegram.org/bot{$token}/setMessageReaction";

		$data = array(
			'chat_id' => $chat_id,
			'message_id' => $message_id,
			'reaction' => json_encode( array(
				array(
					'type' => 'emoji',
					'emoji' => $reaction
				)
			) )
		);

		$response = wp_remote_post( $url, array(
			'body' => $data,
			'timeout' => 15
		) );

		if ( is_wp_error( $response ) ) {
			//error_log( 'WP TGComment Handler: Ошибка отправки реакции: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! $result || ! isset( $result['ok'] ) || ! $result['ok'] ) {
			error_log( 'WP TGComment Handler: Неверный ответ API при отправке реакции. Код:' . $code . ' Ответ:' . $body );
			return false;
		}

		error_log( "WP TGComment Handler: Реакция {$reaction} успешно отправлена на сообщение {$message_id}" );
		return true;
	}

	/**
	 * Получение username бота через API getMe
	 *
	 * Запрашивает информацию о боте и кеширует результат на 1 час.
	 * Используется для создания ссылок Telegram Login Widget.
	 *
	 * @return string Username бота или пустая строка при ошибке
	 */
	public static function get_bot_username() {
		$token = WP_TGComment::get_telegram_token();

		if ( empty( $token ) ) {
			return '';
		}

		// Кешируем информацию о боте
		$cache_key = 'wp_tgcomment_bot_info';
		$bot_info = get_transient( $cache_key );

		if ( ! $bot_info ) {
			$url = "https://api.telegram.org/bot{$token}/getMe";
			$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

			if ( ! is_wp_error( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );

				if ( $data && isset( $data['ok'] ) && $data['ok'] && isset( $data['result']['username'] ) ) {
					$bot_info = $data['result'];
					set_transient( $cache_key, $bot_info, 3600 ); // Кешируем на час
				}
			}
		}

		return isset( $bot_info['username'] ) ? $bot_info['username'] : '';
	}

	/**
	 * Удаление таблицы входящих сообщений при деактивации плагина
	 *
	 * Полностью удаляет таблицу wp_tgcomments_incoming из базы данных.
	 * Используется только при деактивации плагина и включенной опции удаления.
	 *
	 * @return void
	 */
	public static function drop_incoming_messages_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_INCOMING;
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

		error_log( 'WP TGComment: Таблица входящих сообщений удалена' );
	}

	/**
	 * Получение записей пользователя (консультаций)
	 *
	 * Возвращает WP_Query с записями типа консультация, где пользователь
	 * указан как клиент в метаполе client_id.
	 *
	 * @param int $user_id ID пользователя WordPress
	 * @return WP_Query Объект запроса с найденными записями
	 */
	public static function get_user_posts( $user_id ) {

		$query = new WP_Query( [
			'meta_query'  => [
				'relation' => 'AND',
				[
					'key'   => 'client_id',
					'value' => $user_id
				],
			],
			'post_type'   => Pharma::CONSULTATION_POST_TYPE, // Pharma::CONSULTATION_POST_TYPE
			'post_status' => [ 'publish' ],
			'suppress_filters' => true,

		] );

		return $query;
	}

	/**
	 * Проверка и установка активной консультации пользователя
	 *
	 * Проверяет существование активной консультации в метаполе пользователя.
	 * Если нет или консультация недоступна - устанавливает первую доступную.
	 *
	 * @param int $user_id ID пользователя WordPress
	 * @return int|false ID активной консультации или false если консультаций нет
	 */
	public static function ensure_user_has_telegram_post_id( $user_id ) {
		$current_post_id = get_user_meta( $user_id, self::USER_TELEGRAM_POST_ID_META, true );
		$user_posts = self::get_user_posts( $user_id );
		if ( !$user_posts->have_posts() ) {
			return false;
		}
		if ( $current_post_id ) {
			// Значение есть - проверяем существование записи
			foreach ( $user_posts->posts as $post ) {
				if ( $post->ID == $current_post_id ) {
					// Запись существует и доступна
					return $post->ID;
				}
			}

			// Запись не существует - стираем метаполе
			delete_user_meta( $user_id, self::USER_TELEGRAM_POST_ID_META );
		}

		// Устанавливаем первую доступную запись
		$first_post = $user_posts->posts[0];
		update_user_meta( $user_id, self::USER_TELEGRAM_POST_ID_META, $first_post->ID );
		error_log( "WP TGComment: Установлен wp_telegram_post_id = {$first_post->ID} для пользователя {$user_id}" );

		return $first_post->ID;
	}

	/**
	 * Обработка callback query (нажатие на inline кнопки)
	 *
	 * Обрабатывает нажатия на inline кнопки в сообщениях Telegram.
	 * Поддерживает выбор активной консультации.
	 *
	 * @param array $callback_query Данные callback query от Telegram API
	 *                              Содержит: id, data, from, message
	 * @return void
	 */
	public static function process_callback_query( $callback_query ) {
					error_log( "WP TGComment: Получен callback query: " . json_encode( $callback_query, JSON_UNESCAPED_UNICODE ) );

		$callback_query_id = $callback_query['id'];
		$chat_id = $callback_query['message']['chat']['id'];
		$callback_data = $callback_query['data'];
		$from = $callback_query['from'];

		if ( ! $from ) {
			error_log( "WP TGComment: Отсутствуют данные пользователя в callback query" );
			self::send_message( $chat_id, "❌ Ошибка данных пользователя" );
			return;
		}

		$telegram_user_id = $from['id'];

		// Ищем пользователя WordPress
		$wp_user = self::find_wp_user_by_telegram_id( $telegram_user_id );

		if ( ! $wp_user ) {
			error_log( "WP TGComment: Пользователь не найден для Telegram ID: {$telegram_user_id}" );
			self::send_message( $chat_id, "❌ Пользователь не найден" );
			return;
		}

		// Обрабатываем выбор записи
		if ( strpos( $callback_data, 'select_post_' ) === 0 ) {
			$post_id = intval( str_replace( 'select_post_', '', $callback_data ) );
			error_log( "WP TGComment: Обрабатываем выбор записи {$post_id} для пользователя {$wp_user->ID}" );

			// Проверяем, что эта запись принадлежит пользователю
			$user_posts = self::get_user_posts( $wp_user->ID );
			$post_found = false;

			foreach ( $user_posts->posts as $post ) {
				if ( $post->ID == $post_id ) {
					$post_found = true;
					break;
				}
			}

			if ( $post_found ) {
				// Проверяем активность консультации
				$is_active = get_post_meta( $post_id, 'is_active', true );

				if ( $is_active != '1' ) {
					// Консультация неактивна
					$message = "❌ Консультация не может быть выбрана - она неактивна.\n\n";
					$message .= "💬 Свяжитесь с владельцем для активации консультации.";
					self::send_message( $chat_id, $message );
					error_log( "WP TGComment: Пользователь {$wp_user->ID} пытался выбрать неактивную запись {$post_id}" );
					return;
				}

				// Сохраняем выбранную запись
				update_user_meta( $wp_user->ID, self::USER_TELEGRAM_POST_ID_META, $post_id );

				$selected_post = get_post( $post_id );
				$message = "✅ Выбрана запись: \"{$selected_post->post_title}\"\n\n";
				$message .= "Теперь все ваши сообщения будут привязаны к этой записи.";

				// Отправляем сообщение
				self::send_message( $chat_id, $message );

				error_log( "WP TGComment: Пользователь {$wp_user->ID} выбрал запись {$post_id}" );
			} else {
				error_log( "WP TGComment: Запись {$post_id} не найдена для пользователя {$wp_user->ID}" );
				self::send_message( $chat_id, "❌ Запись не найдена" );
			}
		} else {
			error_log( "WP TGComment: Неизвестный callback_data: {$callback_data}" );
			self::send_message( $chat_id, "❌ Неизвестная команда" );
		}
	}

	/**
	 * Получение всех записей где пользователь участвует как клиент или доктор
	 *
	 * Ищет записи консультаций где пользователь указан в метаполях
	 * client_id или doctor_id. Используется для поиска контекста ответов.
	 *
	 * @param int $user_id ID пользователя WordPress
	 * @return WP_Query Объект запроса с найденными записями
	 */
	public static function get_user_related_posts( $user_id ) {
		$query = new WP_Query( [
			'meta_query'  => [
				'relation' => 'OR',
				[
					'key'   => 'client_id',
					'value' => $user_id
				],
				[
					'key'   => 'doctor_id',
					'value' => $user_id
				],
			],
			'post_type'   => Pharma::CONSULTATION_POST_TYPE,
			'post_status' => [ 'publish' ],
			'suppress_filters' => true,
			'posts_per_page' => -1, // Получаем все посты
		] );

		return $query;
	}

	/**
	 * Поиск комментария по ID сообщения Telegram для ответов
	 *
	 * Сложная логика поиска комментария WordPress по ID сообщения Telegram
	 * в контексте ответа на сообщение. Учитывает разные сценарии:
	 * - Ответ на своё сообщение (поиск по tg_incoming_message_id)
	 * - Ответ на чужое сообщение (поиск по tg_outgoing_message_id)
	 *
	 * @param int   $telegram_user_id       ID отвечающего пользователя в Telegram
	 * @param int   $telegram_message_id    ID сообщения на которое отвечают
	 * @param int   $replied_message_from_id ID автора исходного сообщения
	 * @param array $message                Данные текущего сообщения
	 * @return int|null ID записи для создания комментария или null если не найдено
	 */
	public static function find_reply_comment_by_telegram_message_id( $telegram_user_id, $telegram_message_id, $replied_message_from_id, $message ) {
		// Получаем пользователя WordPress
		global $wpdb;
		$wp_user = self::find_wp_user_by_telegram_id( $telegram_user_id );
		if ( ! $wp_user ) {
			error_log( "WP TGComment Handler: Пользователь с Telegram ID {$telegram_user_id} не найден в WordPress" );
			return null;
		}

			$chat_id = $message['chat']['id'];

	// Получаем текст отправляемого сообщения
	$current_message_text = isset($message['text']) ? substr($message['text'], 0, 100) : '[нет текста]';
	if (strlen($message['text'] ?? '') > 100) {
		$current_message_text .= '...';
	}

	error_log( "WP TGComment Handler: 🔍 ПОИСК СВЯЗАННОГО КОММЕНТАРИЯ:" );
	error_log( "  - Chat ID: {$chat_id}" );
	error_log( "  - Отвечающий WP User: {$wp_user->display_name} (ID: {$wp_user->ID})" );
	error_log( "  - Отправляемое сообщение: {$current_message_text}" );
	error_log( "  - Исходное сообщение от Telegram ID: {$replied_message_from_id}" );

		// Получаем все посты в которых пользователь участвует
		$user_posts = self::get_user_related_posts( $wp_user->ID );
		if ( ! $user_posts->have_posts() ) {
			error_log( "WP TGComment Handler: У пользователя {$wp_user->ID} нет связанных постов" );
			return null;
		}

		$post_ids = [];
		foreach ( $user_posts->posts as $post ) {
			$post_ids[] = $post->ID;
		}

		error_log( "  - Постов для поиска: " . count($post_ids) );

		// Поиск в комментариях WordPress
		remove_all_filters( 'the_comments' );
		if ( $telegram_user_id == $replied_message_from_id ) {
			// Пользователь отвечает на свое сообщение - ищем по tg_incoming_chat_id + tg_incoming_message_id
			error_log( "  - Сценарий: Пользователь отвечает на свое сообщение" );
			$query = new WP_Comment_Query();
			$args = [
				'post__in' => $post_ids,
				'author__in' => $wp_user->ID,
				'meta_query' => [
					'relation' => 'AND',
					[
						'key' => 'tg_incoming_chat_id',
						'value' => $chat_id,
						'compare' => '='
					],
					[
						'key' => 'tg_incoming_message_id',
						'value' => $telegram_message_id,
						'compare' => '='
					]
				],
				'number' => 1
			];
			$comments = $query->query($args);

					if ( ! empty( $comments ) ) {
			$comment = $comments[0];
			$comment_text = substr(strip_tags($comment->comment_content), 0, 100);
			if (strlen(strip_tags($comment->comment_content)) > 100) {
				$comment_text .= '...';
			}
			error_log( "  - ✅ Найден комментарий #{$comment->comment_ID} пользователя по tg_incoming_message_id" );
			error_log( "  - Автор найденного комментария: {$comment->comment_author}" );
			error_log( "  - Текст найденного комментария: {$comment_text}" );
			return $comment->comment_post_ID;
			} else {
				// Если не найдено в комментариях, ищем в таблице incoming
				error_log( "  - Поиск в таблице incoming messages" );
				$table_name = $wpdb->prefix . self::TABLE_INCOMING;

				$incoming_record = $wpdb->get_row( $wpdb->prepare(
					"SELECT post_id FROM {$table_name} WHERE chat_id = %d AND tg_message_id = %d AND wp_user_id = %d AND is_deleted = 0",
					$chat_id, $telegram_message_id, $wp_user->ID
				) );

				if ( $incoming_record ) {
					error_log( "  - ✅ Найден post_id {$incoming_record->post_id} в таблице incoming" );
					return $incoming_record->post_id;
				}
			}
		} else {
			// Пользователь отвечает на чужое сообщение - ищем по tg_outgoing_chat_id + tg_outgoing_message_id
			error_log( "  - Сценарий: Пользователь отвечает на чужое сообщение" );
			$query = new WP_Comment_Query();
			$args = [
				'post__in' => $post_ids,
				'author__not_in' => $wp_user->ID,
				'meta_query' => [
					'relation' => 'AND',
					[
						'key' => 'tg_outgoing_chat_id',
						'value' => $chat_id,
						'compare' => '='
					],
					[
						'key' => 'tg_outgoing_message_id',
						'value' => $telegram_message_id,
						'compare' => '='
					]
				],
				'number' => 1
			];
			$comments = $query->query($args);

					if ( ! empty( $comments ) ) {
			$comment = $comments[0];
			$comment_text = substr(strip_tags($comment->comment_content), 0, 100);
			if (strlen(strip_tags($comment->comment_content)) > 100) {
				$comment_text .= '...';
			}
			error_log( "  - ✅ Найден комментарий #{$comment->comment_ID} другого пользователя по tg_outgoing_message_id" );
			error_log( "  - Автор найденного комментария: {$comment->comment_author}" );
			error_log( "  - Текст найденного комментария: {$comment_text}" );
			return $comment->comment_post_ID;
			}
		}

		error_log( "  - ❌ КОММЕНТАРИЙ НЕ НАЙДЕН ни в комментариях, ни в таблице incoming" );
		return null;
	}

	/**
	 * Обработчик события pharma_paidtill_notify для отправки уведомлений в Telegram
	 *
	 * @param int $client_id ID клиента
	 * @param int $doctor_id ID доктора
	 */
	public static function handle_pharma_paidtill_notify( $client_id, $doctor_id ) {
		// Получаем данные пользователей
		$client = get_user_by( 'ID', $client_id );
		$doctor = get_user_by( 'ID', $doctor_id );

		if ( ! $client || ! $doctor ) {
			error_log( "WP TGComment Handler: Не удалось получить данные пользователей. Client ID: {$client_id}, Doctor ID: {$doctor_id}" );
			return;
		}

		// Проверяем наличие Telegram ID у клиента
		$telegram_id = get_user_meta( $client_id, 'tg_login_id', true );
		if ( ! $telegram_id ) {
			error_log( "WP TGComment Handler: У клиента {$client_id} ({$client->user_login}) нет Telegram ID" );
			return; // Если у клиента нет Telegram ID, выходим
		}

		// Получаем timestamp окончания доступа
		$timestamp = get_user_meta( $client_id, 'paidtill_' . $doctor_id, true );
		if ( ! $timestamp ) {
			error_log( "WP TGComment Handler: Не найдена дата окончания доступа для клиента {$client_id} к доктору {$doctor_id}" );
			return;
		}

		$date_time_obj = DateTime::createFromFormat( "U", $timestamp );
		if ( ! $date_time_obj ) {
			error_log( "WP TGComment Handler: Неверный формат timestamp: {$timestamp}" );
			return;
		}

		$current_time = time();
		$message = '';

		// Проверяем статус доступа
		$three_days_from_now = $current_time + ( 86400 * 3 ) + 1;

		if ( $timestamp > $current_time && $timestamp <= $three_days_from_now ) {
			// Уведомление о скором окончании доступа (в течение следующих 3 дней)
			$formatted_date = $date_time_obj->format('d.m.Y H:i');
			$message = "⏰ <b>Уведомление о доступе</b>\n\n";
			$message .= "Ваш доступ к консультациям доктора <b>{$doctor->display_name}</b> будет действовать до <b>{$formatted_date}</b>.\n\n";
			$message .= "💡 Для продления доступа обратитесь к администратору.";

		} elseif ( $timestamp <= $current_time ) {
			// Уведомление об окончании доступа
			$message = "🚫 <b>Доступ закрыт</b>\n\n";
			$message .= "Ваш доступ к консультациям доктора <b>{$doctor->display_name}</b> закрыт.\n\n";
			$message .= "📧 Для продления напишите на <b>ask@curshen.info</b>";
		}

		// Отправляем сообщение, если оно сформировано
		if ( $message ) {
			$result = self::send_message( $telegram_id, $message );
			if ( $result ) {
				error_log( "WP TGComment Handler: ✅ Уведомление о доступе отправлено клиенту {$client_id} ({$client->user_login}) в Telegram" );
			} else {
				error_log( "WP TGComment Handler: ❌ Ошибка отправки уведомления о доступе клиенту {$client_id} ({$client->user_login})" );
			}
		} else {
			error_log( "WP TGComment Handler: Нет подходящего условия для отправки уведомления. Timestamp: {$timestamp}, Current: {$current_time}" );
		}
	}


}

