from tortoise.models import Model
from tortoise import fields

class Channel(Model):
    id = fields.IntField(pk=True)
    channel_id = fields.BigIntField(unique=True)
    username = fields.CharField(max_length=32, unique=True)
    reactions = fields.JSONField()
