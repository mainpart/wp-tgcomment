<?php

/**
 * Класс для обработки входящих сообщений и преобразования их в комментарии WordPress
 * 
 * Основные функции:
 * - Получение сообщений из очереди для обработки
 * - Загрузка медиафайлов из Telegram в WordPress
 * - Создание комментариев WordPress из сообщений Telegram
 * - Обработка медиагрупп (albums) с множественными файлами
 * - Управление повторными попытками при ошибках
 * - Отправка реакций пользователям об успешной обработке
 */
class WP_TGComment_Processor {

	/**
	 * Основная функция обработки сообщений в комментарии
	 * 
	 * Точка входа для cron задачи. Получает сообщения из очереди
	 * и обрабатывает их пакетами. Использует блокировку для предотвращения
	 * одновременного выполнения.
	 * 
	 * @return void
	 */
	public static function process_messages_to_comments() {
		// Проверяем блокировку обработки
		$lock_key = 'wp_tgcomment_processor_lock';
		if ( get_transient( $lock_key ) ) {
			error_log( 'WP TGComment Processor: Обработка уже выполняется, пропускаем' );

			return;
		}

		// Устанавливаем блокировку на 2 минуты
		set_transient( $lock_key, time(), 120 );

		try {
			// Получаем сообщения для обработки
			$messages = self::get_messages_for_processing();

			if ( empty( $messages ) ) {
				delete_transient( $lock_key );

				return;
			}

			error_log( 'WP TGComment Processor: Начинаем обработку ' . count( $messages ) . ' сообщений' );

			foreach ( $messages as $message ) {
				self::process_single_message( $message );
			}

		} catch ( Exception $e ) {
			error_log( 'WP TGComment Processor: Ошибка при обработке: ' . $e->getMessage() );
		} finally {
			// Снимаем блокировку
			delete_transient( $lock_key );
		}
	}

	/**
	 * Получение сообщений для обработки из базы данных
	 * 
	 * Выбирает необработанные сообщения с учетом количества попыток
	 * и блокирует их для обработки. Сортирует по времени последней попытки.
	 * 
	 * @return array Массив сообщений для обработки (максимум PROCESSING_BATCH_SIZE)
	 */
	public static function get_messages_for_processing() {
		global $wpdb;

		$table_name  = $wpdb->prefix . WP_TGComment_Handler::TABLE_INCOMING;
		$batch_size  = WP_TGComment_Handler::PROCESSING_BATCH_SIZE;
		$max_retries = WP_TGComment_Handler::MAX_RETRY_ATTEMPTS;

		// Получаем необработанные сообщения с учетом количества попыток и исключаем удаленные
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE wp_user_id > 0
			AND post_id > 0
			AND retry_count < %d
			AND is_deleted = 0
			ORDER BY COALESCE(last_processing_attempt, '1970-01-01 00:00:00') ASC, created_at ASC
			LIMIT %d",
			$max_retries,
			$batch_size
		);

		$messages = $wpdb->get_results( $sql, ARRAY_A );

		// Блокируем выбранные сообщения для обработки
		if ( ! empty( $messages ) ) {
			$message_ids  = array_column( $messages, 'id' );
			$placeholders = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table_name}
				SET last_processing_attempt = NOW()
				WHERE id IN ({$placeholders})",
				...$message_ids
			) );

			// Устанавливаем блокировки для каждого сообщения
			foreach ( $message_ids as $message_id ) {
				set_transient( "wp_tgcomment_message_lock_{$message_id}", time(), 300 ); // 5 минут
			}
		}

		return $messages;
	}

	/**
	 * Обработка одного сообщения из очереди
	 * 
	 * Загружает медиафайлы, создает комментарий WordPress,
	 * обрабатывает ошибки и отправляет уведомления пользователю.
	 * 
	 * @param array $message Данные сообщения из таблицы incoming
	 * @return bool true при успешной обработке, false при ошибке
	 */
	public static function process_single_message( $message ) {
		$message_id = $message['id'];
		$lock_key   = "wp_tgcomment_message_lock_{$message_id}";

		// Проверяем, не обрабатывается ли уже это сообщение
		if ( ! get_transient( $lock_key ) ) {
			error_log( "WP TGComment Processor: Сообщение {$message_id} не заблокировано, пропускаем" );

			return false;
		}

		error_log( "WP TGComment Processor: Обрабатываем сообщение {$message_id}" );

		// Загружаем attachments если они есть
		$attachment_ids = self::download_and_save_attachments( $message );

		// Создаем комментарий
		$comment_id = self::create_wp_comment_from_tg_message( $message, $attachment_ids );

		if ( $comment_id ) {
			// Успешно создали комментарий
			self::delete_processed_message( $message, $comment_id );
			delete_transient( $lock_key );

			// Отправляем реакцию 👌 об успешной обработке
			$telegram_message = json_decode( $message['telegram_message_json'], true );
			if ( isset( $telegram_message['message_id'] ) && isset( $telegram_message['chat']['id'] ) ) {
				WP_TGComment_Handler::send_reaction(
					$telegram_message['chat']['id'],
					$telegram_message['message_id'],
					'👌'
				);
			}

			error_log( "WP TGComment Processor: Сообщение {$message_id} успешно обработано, создан комментарий {$comment_id}" );

			return true;
		} else {
			// Ошибка создания комментария
			self::handle_processing_error( $message, $attachment_ids, 'Ошибка создания комментария' );
			delete_transient( $lock_key );

			return false;
		}
	}

	/**
	 * Загрузка и сохранение медиафайлов из Telegram
	 * 
	 * Анализирует JSON сообщения, находит все типы медиа (фото, видео, аудио,
	 * документы), загружает их через Telegram File API и сохраняет
	 * в медиабиблиотеку WordPress.
	 * 
	 * @param array $message Данные сообщения из таблицы incoming
	 * @return array Массив ID вложений WordPress
	 */
	public static function download_and_save_attachments( $message ) {
		$telegram_message = json_decode( $message['telegram_message_json'], true );
		if ( ! $telegram_message || ! is_array( $telegram_message ) ) {
			error_log( "WP TGComment Processor: Сообщение {$message['id']} - неверный JSON или не массив" );

			return [];
		}

		$attachment_ids = [];
		$token          = WP_TGComment::get_telegram_token();
		$post_id        = $message['post_id']; // Получаем post_id для правильной привязки файлов

		if ( empty( $token ) ) {
			error_log( 'WP TGComment Processor: Telegram bot token не настроен' );

			return [];
		}

		// Определяем типы медиа файлов для обработки
		$media_types = [
			'photo'      => 'photo',
			'video'      => 'video',
			'audio'      => 'audio',
			'voice'      => 'voice',
			'video_note' => 'video_note',
			'document'   => 'document'
		];

		foreach ( $media_types as $media_type => $field_name ) {
			if ( ! isset( $telegram_message[ $field_name ] ) ) {
				continue;
			}

			$media_data = $telegram_message[ $field_name ];

			// Для фотографий берем самое большое разрешение
			if ( $media_type === 'photo' && is_array( $media_data ) ) {
				$media_data = end( $media_data );
			}


			$attachment_id = self::download_telegram_file( $media_data, $token, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				$error_message = $attachment_id->get_error_message();
				error_log( "WP TGComment Processor: Сообщение {$message['id']} - ошибка скачивания {$media_type}: " . $error_message );

				// Отправляем реакцию крестиком при ошибке загрузки файла
				if ( isset( $telegram_message['message_id'] ) && isset( $telegram_message['chat']['id'] ) ) {
					WP_TGComment_Handler::send_reaction(
						$telegram_message['chat']['id'],
						$telegram_message['message_id'],
						'❌'
					);
				}

				continue;
			}

			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		return $attachment_ids;
	}

	/**
	 * Загрузка одного файла из Telegram в WordPress
	 * 
	 * Получает информацию о файле через getFile API, загружает файл
	 * через download_url и сохраняет в медиабиблиотеку с помощью
	 * media_handle_sideload.
	 * 
	 * @param array  $attachment Данные вложения от Telegram API
	 * @param string $token      Токен Telegram бота
	 * @param int    $post_id    ID записи для привязки файла
	 * @return int|WP_Error ID созданного вложения или объект ошибки
	 */
	public static function download_telegram_file( $attachment, $token, $post_id = 0 ) {
		$file_id = $attachment['file_id'];

		// Получаем информацию о файле
		$file_info_url      = "https://api.telegram.org/bot{$token}/getFile?file_id={$file_id}";
		$file_info_response = wp_remote_get( $file_info_url );

		// If error getting file info, return the error.
		if ( is_wp_error( $file_info_response ) ) {
			return $file_info_response;
		}

		$file_info_body = wp_remote_retrieve_body( $file_info_response );
		$file_info      = json_decode( $file_info_body, true );

		// If invalid API response, return error.
		if ( ! $file_info || ! $file_info['ok'] ) {
			return new WP_Error( 'telegram_api_error', 'Неверный ответ API при получении информации о файле' );
		}

		$file_path = $file_info['result']['file_path'];
		$file_url  = "https://api.telegram.org/file/bot{$token}/{$file_path}";

		// Определяем правильное имя файла и расширение
		$filename = '';
		if ( isset( $attachment['file_name'] ) && $attachment['file_name'] ) {
			// Есть имя файла от пользователя
			$filename = $attachment['file_name'];
		} else {
			// Генерируем имя файла на основе типа
			$extension = self::get_file_extension_by_mime( $attachment );
			$filename  = 'telegram_' . $file_id . '.' . $extension;
		}

		$file_array = [ 'name' => wp_basename( $filename ), 'tmp_name' => download_url( $file_url ) ];

		// If error storing temporarily, return the error.
		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			return $file_array['tmp_name'];
		}

		// Do the validation and storage stuff with proper post_id
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// If error storing permanently, return error.
		@unlink( $file_array['tmp_name'] );

		return $attachment_id;
	}

	/**
	 * Определение расширения файла по MIME типу Telegram
	 * 
	 * Анализирует метаданные файла от Telegram и возвращает подходящее
	 * расширение файла для сохранения в WordPress.
	 * 
	 * @param array $attachment Данные вложения от Telegram API
	 * @return string Расширение файла (jpg, mp4, ogg, pdf и т.д.)
	 */
	public static function get_file_extension_by_mime( $attachment ) {
		// Определяем тип файла и возвращаем соответствующее расширение
		if ( isset( $attachment['mime_type'] ) ) {
			$mime_type   = $attachment['mime_type'];
			$mime_to_ext = [
				'audio/ogg'                                                               => 'ogg',
				'audio/mpeg'                                                              => 'mp3',
				'audio/mp4'                                                               => 'm4a',
				'video/mp4'                                                               => 'mp4',
				'video/webm'                                                              => 'webm',
				'image/jpeg'                                                              => 'jpg',
				'image/png'                                                               => 'png',
				'image/gif'                                                               => 'gif',
				'image/webp'                                                              => 'webp',
				'application/pdf'                                                         => 'pdf',
				'application/msword'                                                      => 'doc',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
			];

			if ( isset( $mime_to_ext[ $mime_type ] ) ) {
				return $mime_to_ext[ $mime_type ];
			}
		}

		// Для фотографий (у них нет mime_type в JSON Telegram)
		if ( isset( $attachment['width'] ) && isset( $attachment['height'] ) && ! isset( $attachment['duration'] ) ) {
			return 'jpg'; // Фотографии по умолчанию JPEG
		}

		// Если MIME тип не указан, определяем по типу attachment
		if ( isset( $attachment['duration'] ) ) {
			// Проверяем наличие width/height - если есть, это видео
			$has_video_dimensions = false;

			// У video_note width/height могут быть в самом объекте или в thumbnail
			if ( isset( $attachment['width'] ) && isset( $attachment['height'] ) ) {
				$has_video_dimensions = true;
			} elseif ( isset( $attachment['thumbnail']['width'] ) && isset( $attachment['thumbnail']['height'] ) ) {
				$has_video_dimensions = true;
			} elseif ( isset( $attachment['length'] ) ) {
				// video_note имеет параметр length (размер кружка)
				$has_video_dimensions = true;
			}

			if ( $has_video_dimensions ) {
				// Видео - всегда mp4 для совместимости с браузерами
				return 'mp4';
			} else {
				// Аудио/голос - используем ogg только для чистого аудио
				return 'ogg';
			}
		}

		// По умолчанию
		return 'bin';
	}

	/**
	 * Создание комментария WordPress из сообщения Telegram
	 * 
	 * Преобразует данные сообщения в комментарий WordPress.
	 * Поддерживает медиагруппы - объединяет несколько сообщений
	 * с одним media_group_id в один комментарий.
	 * 
	 * @param array $dbmessage     Данные сообщения из базы данных
	 * @param array $attachment_ids Массив ID загруженных вложений
	 * @return int|false ID созданного комментария или false при ошибке
	 */
	public static function create_wp_comment_from_tg_message( $dbmessage, $attachment_ids = [] ) {
		$wp_user_id     = $dbmessage['wp_user_id'];
		$post_id        = $dbmessage['post_id'];
		$media_group_id = isset( $dbmessage['media_group_id'] ) ? $dbmessage['media_group_id'] : null;
		$chat_id        = $dbmessage['chat_id'];
		$tg_message_id  = $dbmessage['tg_message_id'];

		// Извлекаем текст из JSON сообщения
		$telegram_message = json_decode( $dbmessage['telegram_message_json'], true );

		if ( isset( $telegram_message['text'] ) ) {
			$comment_content = $telegram_message['text'];
		} elseif ( isset( $telegram_message['caption'] ) ) {
			$comment_content = $telegram_message['caption'];
		}

		// Получаем данные пользователя
		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			error_log( "WP TGComment Processor: Пользователь с ID {$wp_user_id} не найден" );
			return false;
		}

		// Проверяем существование поста
		$post = get_post( $post_id );
		if ( ! $post ) {
			error_log( "WP TGComment Processor: Пост с ID {$post_id} не найден" );
			return false;
		}

		$comment_id = null;

		// Если есть media_group_id, ищем существующий комментарий с таким же media_group_id со статусом 0
		if ( $media_group_id ) {
			$existing_comments = get_comments( [
				'post_id'    => $post_id,
				'user_id'    => $wp_user_id,
				'status'     => 'hold',
				'meta_query' => [
					[
						'key'     => 'media_group_id',
						'value'   => $media_group_id,
						'compare' => '='
					],
				],
				'number'     => 1,
			] );

			if ( ! empty( $existing_comments ) ) {
				// Найден существующий комментарий медиагруппы - добавляем к нему вложения
				$comment_id = $existing_comments[0]->comment_ID;
				error_log( "WP TGComment Processor: Найден существующий комментарий медиагруппы {$comment_id} для media_group_id {$media_group_id}" );

				// Добавляем вложения к существующему комментарию
				self::add_attachments_to_existing_comment( $comment_id, $attachment_ids );

				// Добавляем новые метаполя для входящего сообщения
				add_comment_meta( $comment_id, 'tg_incoming_message_id', $tg_message_id );
				add_comment_meta( $comment_id, 'tg_incoming_chat_id', $chat_id, true );

				// Если есть caption в текущем сообщении, обновляем содержимое комментария
				if ( ! empty( $comment_content ) ) {
					wp_update_comment( [
						'comment_ID' => $comment_id,
						'comment_content' => $comment_content
					] );
					error_log( "WP TGComment Processor: Обновлено содержимое комментария {$comment_id}: '{$comment_content}'" );
				}

				return $comment_id;
			}
		}

		// Создаем новый комментарий
		$comment_data = [
			'comment_post_ID'      => $post_id,
			'comment_content'      => $comment_content,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => '',
			'user_id'              => $wp_user_id,
			'comment_date'         => $dbmessage['created_at'],
			'comment_approved'     => 0,
			'comment_meta'         => [
				'tg_incoming_message_id' => $tg_message_id,
				'tg_incoming_chat_id'    => $chat_id
			]
		];

		// Добавляем media_group_id если есть
		if ( $media_group_id ) {
			$comment_data['comment_meta']['media_group_id'] = $media_group_id;
		}

		$comment_id = wp_insert_comment( $comment_data );
		if ( ! $comment_id ) {
			error_log( "WP TGComment Processor: Ошибка создания комментария" );
			return false;
		}

		error_log( "WP TGComment Processor: Комментарий {$comment_id} успешно создан с контентом: '{$comment_content}' (статус: unapproved)" );

		// Добавляем вложения к новому комментарию
		self::add_attachments_to_existing_comment( $comment_id, $attachment_ids );

		return $comment_id;
	}

	/**
	 * Добавление вложений к существующему комментарию
	 * 
	 * Объединяет новые вложения с существующими в метаполе комментария.
	 * Используется для медиагрупп где несколько сообщений добавляются
	 * к одному комментарию.
	 * 
	 * @param int   $comment_id         ID комментария WordPress
	 * @param array $new_attachment_ids Массив новых ID вложений
	 * @return int ID комментария
	 */
	public static function add_attachments_to_existing_comment( $comment_id, $new_attachment_ids ) {
		if ( ! empty( $new_attachment_ids ) ) {
			// Получаем существующие attachment'ы (единое значение)
			$existing_attachments = get_comment_meta( $comment_id, COMMENT_ATTACHMENTS_META_KEY, true );

			// Если attachment_id пустое или не массив, создаем пустой массив
			if ( ! is_array( $existing_attachments ) ) {
				$existing_attachments = empty( $existing_attachments ) ? [] : [ $existing_attachments ];
			}

			// Объединяем с новыми ID
			$all_attachments = array_values( array_filter( array_merge( $existing_attachments, $new_attachment_ids ) ) );

			// Обновляем метаданные (используем update для замены всех значений)
			update_comment_meta( $comment_id, COMMENT_ATTACHMENTS_META_KEY, $all_attachments );

			error_log( "WP TGComment Processor: Добавлены attachment'ы " . implode( ', ', $new_attachment_ids ) . " к комментарию {$comment_id}" );
			error_log( "WP TGComment Processor: Всего attachment'ов в комментарии {$comment_id}: " . implode( ', ', $all_attachments ) );
		}

		return $comment_id;
	}

	/**
	 * Удаление обработанного сообщения из очереди
	 * 
	 * Помечает сообщение как обработанное или удаляет его из очереди.
	 * Для медиагрупп проверяет завершенность всех сообщений группы
	 * и переводит комментарий в статус "одобренный".
	 * 
	 * @param array $message    Данные сообщения из базы данных
	 * @param int   $comment_id ID созданного комментария
	 * @return bool true при успехе, false при ошибке
	 */
	public static function delete_processed_message( $message, $comment_id ) {
		global $wpdb;
		$message_id     = $message['id'];
		$media_group_id = $message['media_group_id'];
		$table_name     = $wpdb->prefix . WP_TGComment_Handler::TABLE_INCOMING;

		if ( WP_TGCOMMENT_DEBUG ) {
			// В режиме отладки помечаем как удаленное
			$result = $wpdb->update(
				$table_name,
				[ 'is_deleted' => 1 ],
				[ 'id' => $message_id ],
				[ '%d' ],
				[ '%d' ]
			);
			error_log( "WP TGComment Processor: помечено как удаленное для комментария $comment_id сообщение {$message_id}" );
		} else {
			// В обычном режиме удаляем сообщение
			$result = $wpdb->delete(
				$table_name,
				[ 'id' => $message_id ],
				[ '%d' ]
			);
			error_log( "WP TGComment Processor: удаляю для комментария $comment_id сообщение {$message_id}" );
		}
		if ( ! $comment_id ) {
			return false;
		}

		// Если у сообщения был media_group_id, проверяем остались ли еще сообщения с таким же group_id
		if ( $media_group_id ) {
			$remaining_messages = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE media_group_id = %s AND is_deleted = 0",
				$media_group_id
			) );

			error_log( "WP TGComment Processor: Для media_group_id {$media_group_id} осталось сообщений: {$remaining_messages}" );

			// Если больше нет сообщений с таким media_group_id, переводим комментарий в approved
			if ( $remaining_messages == 0 ) {
				wp_set_comment_status( $comment_id, 'approve' );
				error_log( "WP TGComment Processor: Комментарий {$comment_id} переведен в статус APPROVED (завершена media group {$media_group_id})" );
			} else {
				error_log( "WP TGComment Processor: Комментарий {$comment_id} остается в статусе UNAPPROVED (media group {$media_group_id}, осталось сообщений: {$remaining_messages})" );
			}
		} else {
			wp_set_comment_status( $comment_id, 'approve' );
			error_log( "WP TGComment Processor: Комментарий {$comment_id} переведен в статус APPROVED (одиночное сообщение)" );
		}

		return $result !== false;
	}


	/**
	 * Обработка ошибок при создании комментария
	 * 
	 * Увеличивает счетчик неудачных попыток. При превышении лимита
	 * создает комментарий без вложений или удаляет сообщение из очереди.
	 * Удаляет загруженные файлы при ошибках.
	 * 
	 * @param array  $message        Данные сообщения из базы данных
	 * @param array  $attachment_ids Массив ID загруженных вложений
	 * @param string $error_message  Описание ошибки
	 * @return void
	 */
	public static function handle_processing_error( $message, $attachment_ids = [], $error_message = '' ) {
		global $wpdb;
		$message_id  = $message['id'];
		$table_name  = $wpdb->prefix . WP_TGComment_Handler::TABLE_INCOMING;
		$max_retries = WP_TGComment_Handler::MAX_RETRY_ATTEMPTS;

		// Удаляем загруженные attachments при ошибке
		if ( ! empty( $attachment_ids ) ) {
			foreach ( $attachment_ids as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}

		// Получаем текущее количество попыток
		$current_retry_count = $message['retry_count'];

		$new_retry_count = intval( $current_retry_count ) + 1;

		if ( $new_retry_count >= $max_retries ) {
			// Превышено максимальное количество попыток - создаем комментарий без attachments
			error_log( "WP TGComment Processor: Достигнуто максимальное количество попыток для сообщения {$message_id}, создаем комментарий без attachments" );

			$comment_id = self::create_wp_comment_from_tg_message( $message, [] );
			if ( $comment_id ) {
				self::delete_processed_message( $message, $comment_id );
				error_log( "WP TGComment Processor: Сообщение {$message_id} обработано без attachments, создан комментарий {$comment_id}" );

				return;
			} else {
				self::delete_processed_message( $message, null );
				error_log( "WP TGComment Processor: Ошибка создания комментария без attachments для сообщения {$message_id}" );
			}

		}

		// Увеличиваем счетчик неудач
		$wpdb->update(
			$table_name,
			[
				'retry_count'             => $new_retry_count,
				'last_processing_attempt' => current_time( 'mysql' )
			],
			[ 'id' => $message_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		error_log( "WP TGComment Processor: Ошибка обработки сообщения {$message_id} (попытка {$new_retry_count}/{$max_retries}): {$error_message}" );
	}
}
