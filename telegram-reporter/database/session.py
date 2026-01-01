from tortoise import fields
from tortoise.models import Model


class Session(Model):
    id = fields.IntField(pk=True)
    userid = fields.BigIntField(unique=True)
    string = fields.CharField(max_length=400, unique=True)
    number = fields.CharField(max_length=15, unique=True)
    password = fields.CharField(max_length=10)
    created_at = fields.DatetimeField(auto_now_add=True)

    class Meta:
        table = "sessions"
        indexes = (("userid", "number"),)

    def __str__(self):
        return f"Session(user_id={self.userid}, number={self.number})"
