from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import List, Dict, Any, Optional
import numpy as np
import pandas as pd
from scipy.stats import pearsonr
from sklearn.linear_model import LinearRegression

app = FastAPI(title="APIs Hub Analytics Engine", version="1.0.0")

class TimeSeriesData(BaseModel):
    dates: List[str]
    values: List[float]

class CorrelationRequest(BaseModel):
    series_x: TimeSeriesData
    series_y: TimeSeriesData

class RegressionRequest(BaseModel):
    independent_vars: Dict[str, TimeSeriesData]
    dependent_var: TimeSeriesData

@app.get("/health")
def health_check():
    return {"status": "ok", "service": "analytics-engine"}

@app.post("/api/v1/stats/correlation")
def calculate_correlation(payload: CorrelationRequest):
    """
    Calculates the Pearson correlation coefficient between two time series.
    """
    # Align data by date
    df_x = pd.DataFrame({"date": payload.series_x.dates, "x": payload.series_x.values})
    df_y = pd.DataFrame({"date": payload.series_y.dates, "y": payload.series_y.values})
    
    df = pd.merge(df_x, df_y, on="date", how="inner").dropna()
    
    if len(df) < 2:
        raise HTTPException(status_code=400, detail="Not enough overlapping data points for correlation.")
        
    r_value, p_value = pearsonr(df["x"], df["y"])
    
    return {
        "correlation_coefficient": float(r_value),
        "p_value": float(p_value),
        "data_points": len(df)
    }

@app.post("/api/v1/stats/regression")
def calculate_regression(payload: RegressionRequest):
    """
    Performs multiple linear regression.
    """
    dfs = []
    # Build dataframe for dependent variable
    df_y = pd.DataFrame({"date": payload.dependent_var.dates, "y": payload.dependent_var.values})
    dfs.append(df_y)
    
    # Build dataframes for independent variables
    for var_name, ts_data in payload.independent_vars.items():
        df_x = pd.DataFrame({"date": ts_data.dates, var_name: ts_data.values})
        dfs.append(df_x)
        
    # Merge all on date
    from functools import reduce
    df = reduce(lambda left, right: pd.merge(left, right, on="date", how="inner"), dfs).dropna()
    
    if len(df) < 2:
        raise HTTPException(status_code=400, detail="Not enough overlapping data points for regression.")
        
    ind_vars = list(payload.independent_vars.keys())
    X = df[ind_vars]
    y = df["y"]
    
    model = LinearRegression()
    model.fit(X, y)
    
    coefficients = {var: float(coef) for var, coef in zip(ind_vars, model.coef_)}
    
    return {
        "baseline_intercept": float(model.intercept_),
        "coefficients": coefficients,
        "r_squared": float(model.score(X, y)),
        "data_points": len(df)
    }

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
