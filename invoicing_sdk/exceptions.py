"""Exceptions for the Invoicing SDK."""


class APIError(Exception):
    def __init__(self, message, status_code=None, response=None):
        super().__init__(message)
        self.status_code = status_code
        self.response = response


class AuthenticationError(APIError):
    pass


class NotFoundError(APIError):
    pass


class ValidationError(APIError):
    pass
