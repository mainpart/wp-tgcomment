<?php

/**
 * Класс для отправки уведомлений клиентам через Telegram
 * 
 * Основные функции:
 * - Мониторинг новых комментариев в консультациях
 * - Определение получателей уведомлений (доктор или клиент)
 * - Добавление уведомлений в очередь отправки
 * - Отправка медиагрупп с файлами в Telegram
 * - Обработка ошибок отправки с повторными попытками
 * - Создание и управление таблицей исходящих уведомлений
 */
class WP_TGComment_Notifier {

	const TABLE_OUTGOING = 'wp_tgcomments_outgoing';
	const NOTIFIER_CRON_HOOK = 'wp_tgcomment_send_notifications';
	const NOTIFIER_INTERVAL = 'wp_tgcomment_notifier_every_minute';
	const MAX_RETRY_ATTEMPTS = 300;
	const PROCESSING_BATCH_SIZE = 10;

	/**
	 * Инициализация класса уведомлений
	 * 
	 * Регистрирует хуки WordPress для отслеживания новых комментариев
	 * и настраивает cron обработчик для отправки уведомлений.
	 * 
	 * @return void
	 */
	public static function init() {
		// Подписываемся на одобрение комментариев (когда комментарий переходит из unapproved в approved)
		add_action( 'comment_unapproved_to_approved', [ self::class, 'handle_new_comment' ],10,1  );
		add_action( 'wp_insert_comment', [ self::class, 'handle_wp_insert' ], 10,2);

		// Регистрируем cron обработчик
		add_action( self::NOTIFIER_CRON_HOOK, [ self::class, 'process_outgoing_notifications' ] );
	}

	/**
	 * Обработчик вставки комментария WordPress
	 * 
	 * Промежуточная функция, которая вызывает основной обработчик
	 * при создании нового комментария.
	 * 
	 * @param int    $comment_id ID комментария
	 * @param object $comment    Объект комментария
	 * @return void
	 */
	public static function handle_wp_insert(  $comment_id,$comment ) {
		self::handle_new_comment($comment);
	}

	/**
	 * Основная логика обработки нового комментария
	 * 
	 * Проверяет тип записи (консультация), определяет автора и получателя,
	 * проверяет настройки Telegram у получателя и добавляет уведомление в очередь.
	 * 
	 * @param object $comment Объект комментария WordPress
	 * @return void
	 */
	public static function handle_new_comment(  $comment ) {

		if ( ! $comment ) {
			return;
		}
		if ($comment->comment_approved!=1) {
			return;
		}
		$post_id = $comment->comment_post_ID;
		$post = get_post( $post_id );

		// Проверяем, что это консультация
		if ( ! $post || $post->post_type !== Pharma::CONSULTATION_POST_TYPE ) {
			return;
		}

		// Получаем doctor_id и client_id из метаполей записи
		$doctor_id = get_post_meta( $post_id, 'doctor_id', true );
		$client_id = get_post_meta( $post_id, 'client_id', true );

		// Определяем автора комментария
		$comment_author_id = $comment->user_id;
		$recipient_id = null;

		if ( $comment_author_id == $doctor_id ) {
			// Комментарий от доктора - отправляем клиенту
			$recipient_id = $client_id;
		} elseif ( $comment_author_id == $client_id ) {
			// Комментарий от клиента - отправляем доктору
			$recipient_id = $doctor_id;
		} else {
			// Комментарий не от доктора и не от клиента - выходим
			return;
		}

		// Проверяем, что у получателя настроена интеграция с Telegram
		$telegram_user_id = get_user_meta( $recipient_id, USER_META_KEY, true );
		if ( ! $telegram_user_id ) {
			return;
		}

		// Добавляем уведомление в очередь
		$notification_id = self::add_notification_to_queue( $comment->comment_ID, $recipient_id );

		if ( $notification_id ) {
			error_log( "WP TGComment Notifier: Добавлено уведомление #{$notification_id} для пользователя {$recipient_id} о комментарии {$comment->comment_ID}" );
		}
	}

	/**
	 * Добавление уведомления в очередь отправки
	 * 
	 * Создает запись в таблице исходящих уведомлений для последующей
	 * обработки cron задачей. Проверяет дублирование уведомлений.
	 * 
	 * @param int $comment_id  ID комментария WordPress
	 * @param int $wp_user_id  ID пользователя-получателя
	 * @return int|false ID созданной записи уведомления или false при ошибке
	 */
	public static function add_notification_to_queue( $comment_id, $wp_user_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_OUTGOING;

		// Проверяем, что уведомление уже не добавлено
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE comment_id = %d AND wp_user_id = %d",
			$comment_id,
			$wp_user_id
		) );

		if ( $existing ) {
			error_log( "WP TGComment Notifier: Уведомление для комментария {$comment_id} и пользователя {$wp_user_id} уже существует" );
			return false;
		}

		$result = $wpdb->insert(
			$table_name,
			array(
				'comment_id' => $comment_id,
				'wp_user_id' => $wp_user_id,
				'created_at' => current_time( 'mysql' ),
				'last_updated_at' => null,
				'retries' => 0
			),
			array(
				'%d', // comment_id
				'%d', // wp_user_id
				'%s', // created_at
				'%s', // last_updated_at
				'%d'  // retries
			)
		);

		return $result !== false ? $wpdb->insert_id : false;
	}

	/**
	 * Обработчик cron события - отправка уведомлений из очереди
	 * 
	 * Основная функция cron задачи. Получает уведомления из очереди
	 * и отправляет их пакетами. Использует блокировку для предотвращения
	 * одновременного выполнения.
	 * 
	 * @return void
	 */
	public static function process_outgoing_notifications() {
		// Проверяем блокировку обработки
		remove_filter( 'the_comments', [ Pharma::class, 'the_comments' ] );
		$lock_key = 'wp_tgcomment_notifier_lock';
		if ( get_transient( $lock_key ) ) {
			error_log( 'WP TGComment Notifier: Обработка уже выполняется, пропускаем' );
			return;
		}

		// Устанавливаем блокировку на 50 секунд (меньше интервала cron)
		set_transient( $lock_key, time(), 50 );

		try {
			// Получаем уведомления для отправки
			$notifications = self::get_notifications_for_processing();

			if ( empty( $notifications ) ) {
				delete_transient( $lock_key );
				return;
			}

			error_log( 'WP TGComment Notifier: Начинаем обработку ' . count( $notifications ) . ' уведомлений' );

			foreach ( $notifications as $notification ) {
				self::process_single_notification( $notification );
			}

		} catch ( Exception $e ) {
			error_log( 'WP TGComment Notifier: Ошибка при обработке: ' . $e->getMessage() );
		} finally {
			// Снимаем блокировку
			delete_transient( $lock_key );
		}
	}

	/**
	 * Получение уведомлений для отправки из базы данных
	 * 
	 * Выбирает необработанные уведомления с учетом количества попыток
	 * и блокирует их для обработки. Сортирует по времени обновления.
	 * 
	 * @return array Массив уведомлений для обработки (максимум PROCESSING_BATCH_SIZE)
	 */
	public static function get_notifications_for_processing() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_OUTGOING;
		$batch_size = self::PROCESSING_BATCH_SIZE;
		$max_retries = self::MAX_RETRY_ATTEMPTS;

				// Получаем необработанные уведомления с учетом количества попыток и исключаем удаленные
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE retries < %d AND is_deleted = 0
			ORDER BY last_updated_at ASC, created_at ASC
			LIMIT %d",
			$max_retries,
			$batch_size
		);

		$notifications = $wpdb->get_results( $sql, ARRAY_A );

		// Блокируем выбранные уведомления для обработки
		if ( ! empty( $notifications ) ) {
			$notification_ids = array_column( $notifications, 'id' );
			$placeholders = implode( ',', array_fill( 0, count( $notification_ids ), '%d' ) );

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table_name}
				SET last_updated_at = NOW()
				WHERE id IN ({$placeholders})",
				...$notification_ids
			) );

			// Устанавливаем блокировки для каждого уведомления (только если их еще нет)
			foreach ( $notification_ids as $notification_id ) {
				$lock_key = "wp_tgcomment_notification_lock_{$notification_id}";
				if ( ! get_transient( $lock_key ) ) {
					set_transient( $lock_key, time(), 300 ); // 5 минут
				}
			}
		}

		return $notifications;
	}

	/**
	 * Обработка одного уведомления из очереди
	 * 
	 * Проверяет валидность комментария и пользователя, получает Telegram ID
	 * и отправляет уведомление. Обрабатывает результат отправки.
	 * 
	 * @param array $notification Данные уведомления из таблицы outgoing
	 * @return bool true при успешной отправке, false при ошибке
	 */
	public static function process_single_notification( $notification ) {
		$notification_id = $notification['id'];
		$lock_key = "wp_tgcomment_notification_lock_{$notification_id}";

		// Проверяем, не обрабатывается ли уже это уведомление
		if ( ! get_transient( $lock_key ) ) {
			return false;
		}

		error_log( "WP TGComment Notifier: Обрабатываем уведомление {$notification_id}" );

		$comment_id = $notification['comment_id'];
		$wp_user_id = $notification['wp_user_id'];

		// Получаем данные комментария
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			self::delete_notification( $notification_id );
			delete_transient( $lock_key );
			return false;
		}

		// Получаем Telegram ID пользователя
		$telegram_user_id = get_user_meta( $wp_user_id, USER_META_KEY, true );
		if ( ! $telegram_user_id ) {
			self::delete_notification( $notification_id );
			delete_transient( $lock_key );
			return false;
		}

		// Отправляем уведомление
		$success = self::send_comment_notification( $telegram_user_id, $comment );

		if ( $success ) {
			// Успешно отправили уведомление
			self::delete_notification( $notification_id );
			delete_transient( $lock_key );
			error_log( "WP TGComment Notifier: Уведомление {$notification_id} успешно отправлено" );
			return true;
		} else {
			// Ошибка отправки - увеличиваем счетчик
			self::handle_notification_error( $notification_id );
			delete_transient( $lock_key );
			return false;
		}
	}

	/**
	 * Отправка уведомления о комментарии в Telegram
	 * 
	 * Формирует текст сообщения с информацией о комментарии и его авторе.
	 * Получает вложения комментария и отправляет через send_media_group.
	 * 
	 * @param int    $telegram_user_id ID пользователя в Telegram
	 * @param object $comment          Объект комментария WordPress
	 * @return bool true при успешной отправке, false при ошибке
	 */
	public static function send_comment_notification( $telegram_user_id, $comment ) {
		// Получаем данные поста и автора комментария
		$post = get_post( $comment->comment_post_ID );
		$comment_author = get_user_by( 'id', $comment->user_id );

		if ( ! $post ) {
			return false;
		}

		// Формируем текст уведомления
		$author_name = $comment_author ? $comment_author->display_name : 'Неизвестный пользователь';
		$message_text = "💬 \"{$post->post_title}\"\n";
		$message_text .= "👤 Автор: {$author_name}\n\n";
		$message_text .= WP_TGComment_Handler::sanitize_telegram_html( $comment->comment_content );

		// Получаем прикрепленные изображения
		$attchments = get_comment_meta( $comment->comment_ID, COMMENT_ATTACHMENTS_META_KEY, true );
		$attchments = is_array( $attchments ) ? $attchments : [$attchments];

		// Отправляем через единый метод send_media_group
		return self::send_media_group( $telegram_user_id, $message_text, $attchments, $comment->comment_ID );
	}

	/**
	 * Определение типа медиа для Telegram API
	 * 
	 * Анализирует MIME тип файла и возвращает соответствующий
	 * тип медиа для Telegram API (photo, video, audio, document).
	 * 
	 * @param int $attachment_id ID вложения WordPress
	 * @return string Тип медиа для Telegram API
	 */
	public static function getMediaTypeForTelegram( $attachment_id ) {
		$mime_type = get_post_mime_type( $attachment_id );

		if ( strpos( $mime_type, 'image/' ) === 0 ) {
			return 'photo';
		}
		if ( strpos( $mime_type, 'video/' ) === 0 ) {
			return 'video';
		}
		if ( strpos( $mime_type, 'audio/' ) === 0 ) {
			return 'audio';
		}

		return 'document';  // для всех остальных типов
	}

	/**
	 * Подготовка файла для загрузки через CURLFile
	 * 
	 * Создает CURLFile объект для отправки файла напрямую в Telegram
	 * без использования URL. Настраивает MIME тип и имя файла.
	 * 
	 * @param int    $attachment_id ID вложения WordPress
	 * @param string $file_key      Уникальный ключ для файла в multipart форме
	 * @return array|WP_Error Данные файла или объект ошибки
	 */
	public static function prepare_attachment_for_upload( $attachment_id, $file_key ) {
		if ( empty( $attachment_id ) ) {
			return new WP_Error( 'empty_attachment_id', 'Пустой ID вложения' );
		}

		// Получаем путь к файлу
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', "Файл не найден для вложения {$attachment_id}: {$file_path}" );
		}

		try {
			// Создаем CURLFile объект
			$curl_file = new CURLFile( $file_path );

			// Устанавливаем MIME тип
			$mime_type = get_post_mime_type( $attachment_id );
			if ( $mime_type ) {
				$curl_file->setMimeType( $mime_type );
			}

			// Устанавливаем имя файла
			$filename = basename( $file_path );
			$curl_file->setPostFilename( $filename );

			return array(
				'curl_file' => $curl_file,
				'attach_key' => "attach://{$file_key}",
				'file_key' => $file_key,
				'media_type' => self::getMediaTypeForTelegram( $attachment_id ),
				'mime_type' => $mime_type,
				'file_size' => filesize( $file_path ),
				'filename' => $filename
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'curl_file_error', 'Ошибка создания CURLFile: ' . $e->getMessage() );
		}
	}

	/**
	 * Отправка сообщения с медиафайлами в Telegram
	 * 
	 * Группирует вложения по типам медиа (фото/видео, аудио, документы)
	 * и отправляет каждую группу отдельно. Поддерживает два режима:
	 * прямую загрузку файлов и отправку по URL.
	 * 
	 * @param int   $chat_id        ID чата в Telegram
	 * @param string $text          Текст сообщения
	 * @param array $attachment_ids Массив ID вложений WordPress
	 * @param int   $comment_id     ID комментария для связи (опционально)
	 * @return bool true при успешной отправке, false при ошибке
	 */
	public static function send_media_group( $chat_id, $text, $attachment_ids = [], $comment_id = null ) {
		$token = WP_TGComment::get_telegram_token();

		// Очищаем текст от неподдерживаемых HTML тегов
		$text = WP_TGComment_Handler::sanitize_telegram_html( $text );

		// Получаем настройки плагина
		$options = WP_TGComment::get_options();
		$send_files_direct = isset( $options['send_files_direct'] ) ? (int)$options['send_files_direct'] : 0;

		// Фильтруем пустые ID вложений
		$attachment_ids = array_filter( $attachment_ids );

		// Если нет вложений, отправляем только текст
		if ( empty( $attachment_ids ) ) {
			$message_id = WP_TGComment_Handler::send_message( $chat_id, $text, null, $comment_id );
			return $message_id ? true : false;
		}

		// Группируем вложения по типам медиа
		$media_groups = array(
			'photo_video' => array(), // Фото и видео можно в одной группе
			'audio' => array(),       // Аудио отдельно
			'document' => array()     // Документы отдельно
		);

		$files_groups = array(
			'photo_video' => array(),
			'audio' => array(),
			'document' => array()
		);

		$has_critical_errors = false;
		$is_first_item = true; // Флаг для добавления caption к первому элементу

		foreach ( $attachment_ids as $index => $attachment_id ) {
			$media_type = self::getMediaTypeForTelegram( $attachment_id );

			// Определяем группу для этого типа медиа
			$group_key = 'document'; // по умолчанию
			if ( $media_type === 'photo' || $media_type === 'video' ) {
				$group_key = 'photo_video';
			} elseif ( $media_type === 'audio' ) {
				$group_key = 'audio';
			}

			// Если включена прямая отправка файлов
			if ( $send_files_direct == 1 ) {
				$file_key = "file_{$index}";
				$attachment_data = self::prepare_attachment_for_upload( $attachment_id, $file_key );

				if ( is_wp_error( $attachment_data ) ) {
					$error_code = $attachment_data->get_error_code();
					error_log( 'WP TGComment Notifier: Ошибка подготовки вложения ' . $attachment_id . ': ' . $attachment_data->get_error_message() );

					// Критические ошибки требуют повтора
					if ( in_array( $error_code, array( 'file_not_found', 'curl_file_error' ) ) ) {
						$has_critical_errors = true;
					}
					continue;
				}

				// Добавляем файл для загрузки
				$files_groups[$group_key][$file_key] = $attachment_data['curl_file'];

							// Создаем элемент медиа с ссылкой на загруженный файл
			$media_item = array(
				'type' => $attachment_data['media_type'],
				'media' => $attachment_data['attach_key']
			);
		} else {
			// Используем URL (старый метод)
			$attachment_url = wp_get_attachment_url( $attachment_id );
			if ( ! $attachment_url ) {
				continue;
			}

			$media_item = array(
				'type' => $media_type,
				'media' => $attachment_url
			);
		}

		// Добавляем caption к первому элементу первой медиагруппы
		if ( $is_first_item && ! empty( $text ) ) {
			$media_item['caption'] = $text;
			$is_first_item = false; // Больше не добавляем caption
		}

		$media_groups[$group_key][] = $media_item;
		}

		// Если есть критические ошибки и нет валидных файлов - возвращаем false для повтора
		if ( $send_files_direct == 1 && $has_critical_errors) {
			error_log( 'WP TGComment Notifier: Критические ошибки при подготовке всех файлов, требуется повтор' );
			return false;
		}

		// Отправляем каждую группу медиа отдельно
		$success_count = 0;
		$total_groups = 0;

		foreach ( $media_groups as $group_key => $media_group ) {
			if ( empty( $media_group ) ) {
				continue;
			}

			$total_groups++;
			$files_for_group = isset( $files_groups[$group_key] ) ? $files_groups[$group_key] : array();

			$group_success = self::send_media_group_to_telegram( $chat_id, $media_group, $files_for_group, $comment_id );

			if ( ! $group_success ) {
				return false;
			}

			if ( $group_success ) {
				$success_count++;
			} else {
				error_log( "WP TGComment Notifier: Ошибка отправки группы {$group_key}" );
			}
		}

		return $success_count > 0;
	}

	/**
	 * Отправка медиагруппы в Telegram через API
	 * 
	 * Выполняет HTTP запрос к Telegram API для отправки медиагруппы.
	 * Поддерживает multipart/form-data для прямой загрузки файлов
	 * и обычные POST запросы для URL.
	 * 
	 * @param int   $chat_id    ID чата в Telegram
	 * @param array $media      Массив медиафайлов для отправки
	 * @param array $files      Массив CURLFile объектов (опционально)
	 * @param int   $comment_id ID комментария для связи (опционально)
	 * @return bool true при успешной отправке, false при ошибке
	 */
	private static function send_media_group_to_telegram( $chat_id, $media, $files = array(), $comment_id = null ) {
		$token = WP_TGComment::get_telegram_token();
		$url = "https://api.telegram.org/bot{$token}/sendMediaGroup";

		// Если есть файлы для загрузки, создаем multipart payload вручную
		if ( ! empty( $files ) ) {
			// Создаем boundary для multipart/form-data
			$boundary = wp_generate_password( 24, false );

			$headers = array(
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary
			);

			$payload = '';

			// Добавляем обычные POST поля
			$post_fields = array(
				'chat_id' => $chat_id,
				'media' => json_encode( $media )
			);

			foreach ( $post_fields as $name => $value ) {
				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
				$payload .= $value . "\r\n";
			}

			// Добавляем файлы
			foreach ( $files as $field_name => $curl_file ) {
				// Получаем данные из CURLFile объекта
				$file_path = $curl_file->getFilename();
				$mime_type = $curl_file->getMimeType();
				$post_filename = $curl_file->getPostFilename();

				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $post_filename . '"' . "\r\n";

				if ( $mime_type ) {
					$payload .= 'Content-Type: ' . $mime_type . "\r\n";
				}

				$payload .= "\r\n";
				$payload .= file_get_contents( $file_path ) . "\r\n";
			}

			$payload .= '--' . $boundary . '--';

			$response = wp_remote_post( $url, array(
				'headers' => $headers,
				'body' => $payload,
				'timeout' => 30
			) );

		} else {
			// Используем wp_remote_post для URLs (обычный способ)
			$data = array(
				'chat_id' => $chat_id,
				'media' => json_encode( $media )
			);

			$response = wp_remote_post( $url, array(
				'body' => $data,
				'timeout' => 30
			) );
		}

		// Обработка ответа одинаковая для обоих случаев
		if ( is_wp_error( $response ) ) {
			error_log( 'WP TGComment Notifier: WP Remote Post ошибка: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );



		if ( $code !== 200 || ! $result || ! isset( $result['ok'] ) || ! $result['ok'] ) {
			error_log( 'WP TGComment Notifier: Ошибка API при отправке медиагруппы. Код:' . $code . ' Ответ:' . $body );

			// Специальная обработка для 403 - бот заблокирован пользователем
			if ( $code === 403 ) {
				do_action('wp_tg_sendmessage_403', $chat_id);
			}

			return false;
		}

		// Если медиагруппа успешно отправлена и передан comment_id, сохраняем связи со всеми сообщениями
		if ( $comment_id && isset( $result['result'] ) && is_array( $result['result'] ) && count( $result['result'] ) > 0 ) {
			$messages = $result['result'];
			$message_ids = array();
			
			foreach ( $messages as $message ) {
				if ( isset( $message['message_id'] ) ) {
					$telegram_message_id = $message['message_id'];
					$message_ids[] = $telegram_message_id;
					
					// Сохраняем каждый message_id отдельно (позволяет множественные значения)
					add_comment_meta( $comment_id, 'tg_outgoing_message_id', $telegram_message_id );
				}
			}
			
			// Chat_id у всех сообщений в медиагруппе одинаковый, сохраняем только один раз
			if ( isset( $messages[0]['chat']['id'] ) ) {
				add_comment_meta( $comment_id, 'tg_outgoing_chat_id', $messages[0]['chat']['id'], true );
			}
			
			error_log( "WP TGComment Notifier: Сохранены связи комментария {$comment_id} с сообщениями медиагруппы: " . implode( ', ', $message_ids ) );
		}

		return true;
	}

	/**
	 * Удаление обработанного уведомления из очереди
	 * 
	 * Помечает уведомление как удаленное (в режиме отладки) или
	 * физически удаляет из таблицы (в обычном режиме).
	 * 
	 * @param int $notification_id ID уведомления для удаления
	 * @return bool true при успехе, false при ошибке
	 */
	public static function delete_notification( $notification_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_OUTGOING;

		if ( WP_TGCOMMENT_DEBUG ) {
			// В режиме отладки помечаем как удаленное
			$result = $wpdb->update(
				$table_name,
				[ 'is_deleted' => 1 ],
				[ 'id' => $notification_id ],
				[ '%d' ],
				[ '%d' ]
			);
		} else {
			// В обычном режиме удаляем уведомление
			$result = $wpdb->delete(
				$table_name,
				[ 'id' => $notification_id ],
				[ '%d' ]
			);
		}

		return $result !== false;
	}

	/**
	 * Обработка ошибки отправки уведомления
	 * 
	 * Увеличивает счетчик неудачных попыток. При превышении лимита
	 * удаляет уведомление из очереди.
	 * 
	 * @param int $notification_id ID уведомления с ошибкой
	 * @return void
	 */
	public static function handle_notification_error( $notification_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_OUTGOING;

		// Используем константу из WP_TGComment_Handler
		$max_retries = WP_TGComment_Handler::MAX_RETRY_ATTEMPTS;

		// Получаем текущее количество попыток
		$current_retries = $wpdb->get_var( $wpdb->prepare(
			"SELECT retries FROM {$table_name} WHERE id = %d",
			$notification_id
		) );

		$new_retries = intval( $current_retries ) + 1;

		if ( $new_retries >= $max_retries ) {
			// Превышено максимальное количество попыток - удаляем уведомление
			error_log( "WP TGComment Notifier: Достигнуто максимальное количество попыток для уведомления {$notification_id} ({$new_retries}/{$max_retries}), удаляем" );
			self::delete_notification( $notification_id );
			return;
		}

		// Увеличиваем счетчик неудач
		$wpdb->update(
			$table_name,
			[
				'retries' => $new_retries,
				'last_updated_at' => current_time( 'mysql' )
			],
			[ 'id' => $notification_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		error_log( "WP TGComment Notifier: Ошибка отправки уведомления {$notification_id} (попытка {$new_retries}/{$max_retries})" );
	}

	/**
	 * Создание таблицы исходящих уведомлений
	 * 
	 * Создает таблицу wp_tgcomments_outgoing для хранения
	 * очереди исходящих уведомлений в Telegram.
	 * 
	 * @return void
	 */
	public static function create_outgoing_notifications_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_OUTGOING;

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) NOT NULL,
			wp_user_id bigint(20) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			last_updated_at datetime DEFAULT NULL,
			retries int(11) DEFAULT 0,
			is_deleted tinyint(1) DEFAULT 0,
			PRIMARY KEY (id),
			KEY comment_id (comment_id),
			KEY wp_user_id (wp_user_id),
			KEY created_at (created_at),
			KEY last_updated_at (last_updated_at),
			KEY retries (retries),
			KEY is_deleted (is_deleted),
			UNIQUE KEY unique_notification (comment_id, wp_user_id)
		) {$wpdb->get_charset_collate()};";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		error_log( 'WP TGComment Notifier: Таблица исходящих уведомлений создана или обновлена' );
	}

	/**
	 * Получение статуса cron события уведомлений
	 * 
	 * Возвращает информацию о состоянии cron задачи отправки уведомлений.
	 * 
	 * @return array Массив с информацией о статусе cron
	 */
	public static function get_notifier_cron_status() {
		$next_scheduled = wp_next_scheduled( self::NOTIFIER_CRON_HOOK );

		return array(
			'is_scheduled' => ! ! $next_scheduled,
			'next_run' => $next_scheduled ? date( 'Y-m-d H:i:s', $next_scheduled ) : 'Не запланировано',
			'next_run_timestamp' => $next_scheduled
		);
	}
}
