# -*- coding: utf-8 -*-
"""Factories to help in tests."""
from factory import PostGenerationMethodCall, Sequence
from factory.alchemy import SQLAlchemyModelFactory
from airscore.database import db
from airscore.user.models import User
from db.tables import TblCompetition


class BaseFactory(SQLAlchemyModelFactory):
    """Base factory."""

    class Meta:
        """Factory configuration."""

        abstract = True
        sqlalchemy_session = db.session


class UserFactory(BaseFactory):
    """User factory."""

    username = Sequence(lambda n: f"user{n}")
    email = Sequence(lambda n: f"user{n}@example.com")
    password = PostGenerationMethodCall("set_password", "example")
    active = True
    access = 'scorekeeper'

    class Meta:
        """Factory configuration."""

        model = User


class CompetitionTableFactory(BaseFactory):
    """ db competition factory"""

    class Meta:
        """Factory configuration."""

        model = TblCompetition


