from telegram import Update
from telegram.ext import Application, CommandHandler, MessageHandler, filters, ContextTypes
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

    init_db()
    app.run_polling()

if __name__ == '__main__':
    main()