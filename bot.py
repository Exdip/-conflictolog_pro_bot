from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, MessageHandler, filters, ContextTypes, CallbackQueryHandler 
from config import Config
from database import init_db, add_user, is_subscribed
from analysis import analyze_conflict
from payment import create_payment

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    add_user(user_id)
    await update.message.reply_text(
        "Привет! Я Конфликтолог PRO. Опишите свой конфликт, и я помогу разобраться.\n\n"
        "Если у вас нет подписки — 490₽/мес. Напишите /subscribe, чтобы оформить."
    )

async def send_menu(update, context):
    keyboard = [
        [InlineKeyboardButton("Посмотреть примеры", callback_data='buy_course')],
        [InlineKeyboardButton("Оплатить подписку", callback_data='free_lesson')],
        [InlineKeyboardButton("Контакты", callback_data='contacts')]
    ]
    reply_markup = InlineKeyboardMarkup(keyboard)
    await update.message.reply_text('Привет! Выберите:', reply_markup=reply_markup)

async def subscribe(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    url = create_payment(490, "Подписка на Конфликтолог PRO", user_id)
    await update.message.reply_text(f"Оплатите подписку по ссылке:\n{url}")

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    #     if not is_subscribed(user_id):
    #     await update.message.reply_text("У вас нет подписки. Напишите /subscribe.")
    #     return


    user_message = update.message.text
    analysis_result = analyze_conflict(user_message)
    await update.message.reply_text(analysis_result, parse_mode='HTML')

def main():
    app = Application.builder().token(Config.TELEGRAM_BOT_TOKEN).build()

    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("subscribe", subscribe))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))
    app.add_handler(CommandHandler("start", send_menu))  # ← если вы добавили меню
    app.add_handler(CallbackQueryHandler(button_handler))

    # Вызов init_db() должен быть ВНУТРИ main()
    init_db()

    # Запуск бота
    app.run_polling()

if __name__ == '__main__':
    main()