import yookassa
import config

yookassa.Configuration.configure(config.YOOKASSA_SHOP_ID, config.YOOKASSA_SECRET_KEY)

def create_payment(amount, description, user_id):
    payment = yookassa.Payment.create({
        "amount": {
            "value": str(amount),
            "currency": "RUB"
        },
        "confirmation": {
            "type": "redirect",
            "return_url": "https://t.me/conflictolog_pro_bot"
        },
        "capture": True,
        "description": description,
        "metadata": {"user_id": user_id}
    })
    return payment.confirmation.confirmation_url