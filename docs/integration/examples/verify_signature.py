"""Vérifie la signature d'un webhook Core (Python ≥ 3.8, aucune dépendance).

Avec Flask : verify_core_signature(secret, request.get_data(as_text=True),
request.headers.get("X-Timestamp", ""), request.headers.get("X-Signature", ""))
"""
import hashlib
import hmac
import os
import sys
import time


def verify_core_signature(secret: str, raw_body: str, timestamp: str, signature: str, tolerance_seconds: int = 300) -> bool:
    # 1. Rejeter un message trop ancien (anti-rejeu).
    if not timestamp.isdigit() or abs(int(time.time()) - int(timestamp)) > tolerance_seconds:
        return False

    # 2. Recalculer la signature sur "<timestamp>.<corps brut>".
    expected = hmac.new(secret.encode(), f"{timestamp}.{raw_body}".encode(), hashlib.sha256).hexdigest()

    # 3. Comparer en temps constant.
    return hmac.compare_digest(expected, signature)


# Exécutable en ligne de commande (utilisé par les tests de cette doc) :
#   WEBHOOK_SECRET=… X_TIMESTAMP=… X_SIGNATURE=… python3 verify_signature.py < body.json
if __name__ == "__main__":
    ok = verify_core_signature(
        os.environ.get("WEBHOOK_SECRET", ""),
        sys.stdin.read(),
        os.environ.get("X_TIMESTAMP", ""),
        os.environ.get("X_SIGNATURE", ""),
    )
    print("valid" if ok else "invalid")
    sys.exit(0 if ok else 1)
