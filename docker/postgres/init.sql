-- Separate database for the test suite, so `make test` never touches dev data.
CREATE DATABASE ifood_orders_test OWNER ifood;
