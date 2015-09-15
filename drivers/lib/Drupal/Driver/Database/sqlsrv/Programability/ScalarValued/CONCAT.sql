CREATE FUNCTION [CONCAT](@op1 sql_variant, @op2 sql_variant) RETURNS nvarchar(4000) AS
            BEGIN
              DECLARE @result nvarchar(4000)
              SET @result = CAST(@op1 AS nvarchar(4000)) + CAST(@op2 AS nvarchar(4000))
              RETURN @result
            END