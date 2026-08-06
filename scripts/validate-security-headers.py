import os

def test_security_hardening_docs():
    assert os.path.exists("docs/security.md"), "docs/security.md missing"
    with open("docs/security.md", "r", encoding="utf-8") as f:
        content = f.read()
    assert "Security Headers" in content
    assert "X-Content-Type-Options" in content
    assert "Content-Security-Policy" in content
    print("✅ Security baseline validation passed")

if __name__ == "__main__":
    test_security_hardening_docs()
