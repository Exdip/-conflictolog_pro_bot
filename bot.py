import os
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, MessageHandler, filters, ContextTypes, CallbackQueryHandler
import config
from database import init_db, add_user, is_subscribed
from analysis import analyze_conflict
from payment import create_payment
from speech_recognition import voice_to_text_yandex


async def handle_voice(update, context):
    try:
        voice_duration = update.message.voice.duration
        if voice_duration > 60:
            await update.message.reply_text("Сообщение слишком длинное. Максимум 1 минута.")
            return

        file = await context.bot.get_file(update.message.voice.file_id)
        await file.download_to_drive('voice.oga')

        # Логируем факт скачивания
        print("Файл voice.oga успешно скачан")

        
        api_key = os.getenv("YANDEX_API_KEY")
        folder_id = os.getenv("YANDEX_FOLDER_ID")

        if not api_key or not folder_id:
            await update.message.reply_text("Ошибка: не настроены API-ключи Yandex.")
            return
        
             
        text = voic# Было: text = voice_to_text_yandex('voice.oga')
        # Стало:
        text = voice_to_text_yandex('voice.oga', api_key, folder_id)
        if not text:
            text = "Не удалось распознать речь."

        await update.message.reply_text(f"Вы сказали: {text}")

    except Exception as e:
        print(f"Ошибка в handle_voice: {e}")
        await update.message.reply_text("Произошла ошибка при обработке голосового сообщения.")

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    add_user(user_id)
    await update.message.reply_text(
        "Привет! Я Конфликтолог PRO. Опишите свой конфликт, и я помогу разобраться.\n\n"
        "Если у вас нет подписки — 490₽/мес. Напишите /subscribe, чтобы оформить."
    )

async def send_menu(update, context):
    # 1. Сначала получаем клавиатуру из функции ниже
    markup = get_keyboard()
    
    # 2. Отправляем сообщение с этой клавиатурой
    # (В зависимости от вашей библиотеки команда может отличаться, 
    # обычно это update.message.reply_text или message.answer)
    await update.message.reply_text("Выберите действие:", reply_markup=markup)

# Вместо старой функции get_keyboard
def get_keyboard():
    keyboard = [
        [
            InlineKeyboardButton("Посмотреть примеры", callback_data="examples"),
            InlineKeyboardButton("Оплатить 490 рублей", callback_data="pay")
        ],
        [InlineKeyboardButton("Контакты", callback_data="contacts")],
        [InlineKeyboardButton("🔴 МЕНЮ", callback_data="menu")]
    ]
    return InlineKeyboardMarkup(keyboard)

async def button_handler(update, context):
    query = update.callback_query
    await query.answer()  # Отправляем "ответ" на нажатие

    if query.data == 'examples':
        # Отправляем ссылки на скачивание
        await query.edit_message_text(
            text="Ссылки на примеры:\n"
                 "1. [Пример 1](https://example.com/example1)\n"
                 "2. [Пример 2](https://example.com/example2)\n"
                 "3. [Пример 3](https://example.com/example3)"
        )
    elif query.data == 'subscribe':
        # Создаём платёж и отправляем ссылку
       user_id = query.from_user.id
       url = create_payment(490, "Подписка на Конфликтолог PRO", user_id)
       await query.edit_message_text(
             text=f"Ссылка для оплаты: {url}"
       )
    elif query.data == 'contacts':
        await query.edit_message_text(text="@ckikmru")

async def subscribe(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    url = create_payment(490, "Подписка на Конфликтолог PRO", user_id)
    await update.message.reply_text(f"Оплатите подписку по ссылке:\n{url}")

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id

    user_message = update.message.text
    analysis_result = analyze_conflict(user_message)
    await update.message.reply_text(analysis_result, parse_mode='HTML')

def main():
    app = Application.builder().token(config.TELEGRAM_BOT_TOKEN).build()

    # Обработчики
    app.add_handler(CommandHandler("start", send_menu))
    app.add_handler(CommandHandler("subscribe", subscribe))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))
    app.add_handler(MessageHandler(filters.VOICE, handle_voice))
    app.add_handler(CallbackQueryHandler(button_handler))  # Добавляем обработчик кнопок

    # Инициализация базы данных
    init_db()

    # Запуск бота в режиме polling
    app.run_polling()

if __name__ == '__main__':
    main()